<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Job;
use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Repository\JobRepository;
use App\Util\WorkingHours;

/**
 * Prueft einen (geplanten) Termin und liefert Hinweise – verhindert aber
 * nichts. Gespeichert wird trotzdem; die Hinweise zeigt die Oberflaeche an,
 * damit nichts unbemerkt bleibt:
 *
 *  - arbeitszeit:      Beginn/Ende ausserhalb der regulaeren Arbeitszeit oder in der Pause
 *  - ueberschneidung:  zustaendige Person oder Lehrling ist zur selben Zeit anderweitig eingeplant
 *  - abwesenheit:      zustaendige Person oder Lehrling ist abwesend (Urlaub, Arzttermin, ...)
 */
class ScheduleChecker
{
    public const ARBEITSZEIT = 'arbeitszeit';
    public const UEBERSCHNEIDUNG = 'ueberschneidung';
    public const ABWESENHEIT = 'abwesenheit';

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly AbsenceRepository $absences,
    ) {
    }

    /**
     * @return list<array{type: string, message: string, jobId?: int, absenceId?: int, userId?: int}>
     */
    public function forJob(Job $job): array
    {
        if (!$job->isScheduled() || $job->isDone()) {
            return [];
        }

        \assert(null !== $job->getStartsAt() && null !== $job->getEndsAt());

        return $this->check($job->getStartsAt(), $job->getEndsAt(), $job->getAssignee(), $job->getApprentice(), $job->getId());
    }

    /**
     * @return list<array{type: string, message: string, jobId?: int, absenceId?: int, userId?: int}>
     */
    public function check(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?User $assignee, ?User $apprentice, ?int $excludeJobId = null): array
    {
        $warnings = array_map(
            static fn (string $message): array => ['type' => self::ARBEITSZEIT, 'message' => $message],
            WorkingHours::warnings($startsAt, $endsAt),
        );

        foreach (array_filter([$assignee, $apprentice]) as $person) {
            $exclude = null !== $excludeJobId ? [$excludeJobId] : [];
            foreach ($this->jobs->findOpenInvolving($person, $startsAt, $endsAt, $exclude) as $other) {
                $warnings[] = [
                    'type' => self::UEBERSCHNEIDUNG,
                    'message' => sprintf('%s ist zur selben Zeit auch für „%s“ eingeplant (%s).', $person->getFullName(), $other->getTitle(), $this->describe($other)),
                    'jobId' => (int) $other->getId(),
                    'userId' => (int) $person->getId(),
                ];
            }

            foreach ($this->absences->findInRange($startsAt, $endsAt, [$person]) as $absence) {
                $warnings[] = $this->absenceWarning($person, $absence);
            }
        }

        return $warnings;
    }

    /**
     * Auftraege, die in eine (neue) Abwesenheit fallen – sie bleiben unveraendert
     * im Kalender, werden aber gemeldet.
     *
     * @return list<array{id: int, title: string, startsAt: string|null, endsAt: string|null}>
     */
    public function jobsDuring(Absence $absence): array
    {
        return array_map(static fn (Job $job): array => [
            'id' => (int) $job->getId(),
            'title' => $job->getTitle(),
            'startsAt' => $job->getStartsAt()?->format(\DATE_ATOM),
            'endsAt' => $job->getEndsAt()?->format(\DATE_ATOM),
        ], $this->jobs->findOpenInvolving($absence->getUser(), $absence->getStartsAt(), $absence->getEndsAt()));
    }

    /**
     * @return array{type: string, message: string, absenceId: int, userId: int}
     */
    private function absenceWarning(User $person, Absence $absence): array
    {
        return [
            'type' => self::ABWESENHEIT,
            'message' => sprintf('%s ist abwesend: %s (%s).', $person->getFullName(), $absence->getLabel(), $absence->describePeriod()),
            'absenceId' => (int) $absence->getId(),
            'userId' => (int) $person->getId(),
        ];
    }

    private function describe(Job $job): string
    {
        $start = $job->getStartsAt();
        $end = $job->getEndsAt();
        \assert(null !== $start && null !== $end);

        return $start->format('Y-m-d') === $end->format('Y-m-d')
            ? $start->format('d.m., H:i').'–'.$end->format('H:i')
            : $start->format('d.m., H:i').' – '.$end->format('d.m., H:i');
    }
}
