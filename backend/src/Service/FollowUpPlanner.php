<?php

namespace App\Service;

use App\Entity\Job;
use App\Enum\JobStatus;
use App\Exception\WorkflowException;
use App\Repository\JobRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Intelligente Umplanung: Wird eine eingeplante Arbeit frueher (oder
 * spaeter) als geplant abgeschlossen, schlaegt dieser Dienst vor, die
 * direkt anschliessenden, noch nicht begonnenen Arbeiten desselben
 * Arbeiters nach vorne (bzw. hinten) zu verschieben.
 *
 * Bewusst konservativ:
 *  - Nur eine "Kette" direkt aufeinanderfolgender Termine wird bewegt
 *    (Luecke hoechstens CHAIN_GAP_MINUTES). Eine geplante Luecke – etwa
 *    weil der Kunde erst um 13:00 kommt – bleibt erhalten.
 *  - Begonnene oder abgeschlossene Arbeiten beenden die Kette; sie werden
 *    nie verschoben und ihre Ist-Zeit bleibt unberuehrt.
 *  - Keine Verschiebung ueber eine Tagesgrenze.
 *  - Ueberschneidungen mit anderen Terminen beenden die Kette mit Warnung.
 *  - Endet ein Termin nach dem Arbeitsschluss, wird er nur mit Warnung
 *    und nicht vorausgewaehlt vorgeschlagen.
 *  - Angewendet wird erst nach Bestaetigung (apply), und nur ein
 *    zusammenhaengender Anfang der Kette.
 */
class FollowUpPlanner
{
    public const ROUND_MINUTES = 15;
    public const CHAIN_GAP_MINUTES = 15;
    public const LUNCH_BREAK_MINUTES = 30;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *     jobId: int,
     *     direction: 'earlier'|'later',
     *     shiftMinutes: int,
     *     scheduledEnd: string,
     *     actualEnd: string,
     *     moves: list<array<string, mixed>>,
     *     warnings: list<string>,
     * }|null
     */
    public function preview(Job $done): ?array
    {
        $plan = $this->plan($done);

        if (null === $plan) {
            return null;
        }

        return [
            'jobId' => (int) $done->getId(),
            'direction' => $plan['shift'] < 0 ? 'earlier' : 'later',
            'shiftMinutes' => $plan['shift'],
            'scheduledEnd' => $plan['scheduledEnd']->format(\DATE_ATOM),
            'actualEnd' => $plan['anchor']->format(\DATE_ATOM),
            'moves' => array_map(static fn (array $move): array => [
                'jobId' => $move['job']->getId(),
                'title' => $move['job']->getTitle(),
                'customer' => $move['job']->getCustomer(),
                'from' => ['startsAt' => $move['job']->getStartsAt()?->format(\DATE_ATOM), 'endsAt' => $move['job']->getEndsAt()?->format(\DATE_ATOM)],
                'to' => ['startsAt' => $move['startsAt']->format(\DATE_ATOM), 'endsAt' => $move['endsAt']->format(\DATE_ATOM)],
                'recommended' => $move['recommended'],
                'warning' => $move['warning'],
            ], $plan['moves']),
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * Verschiebt die ausgewaehlten Arbeiten (zusammenhaengender Anfang der
     * Kette) und kuerzt bzw. verlaengert den Termin der abgeschlossenen
     * Arbeit auf ihr tatsaechliches Ende.
     *
     * @param int[] $jobIds
     *
     * @return list<Job> verschobene Arbeiten
     */
    public function apply(Job $done, array $jobIds): array
    {
        return $this->em->wrapInTransaction(function () use ($done, $jobIds): array {
            $this->em->refresh($done, LockMode::PESSIMISTIC_WRITE);
            $plan = $this->plan($done)
                ?? throw new WorkflowException('Für diese Arbeit gibt es keinen Umplanungsvorschlag (mehr).');

            $selected = array_map('intval', $jobIds);
            $moved = [];

            foreach ($plan['moves'] as $move) {
                $job = $move['job'];
                if (!\in_array($job->getId(), $selected, true)) {
                    break; // nur ein zusammenhaengender Anfang – sonst entstuenden Ueberschneidungen
                }

                $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);
                if (JobStatus::Open !== $job->getStatus() || $job->isStarted()) {
                    break; // inzwischen begonnen – nicht mehr verschieben
                }

                $job->schedule($move['startsAt'], $move['endsAt']);
                $moved[] = $job;
            }

            // Die abgeschlossene Arbeit belegt im Kalender nur noch die tatsaechliche Zeit.
            $done->schedule($done->getStartsAt(), $plan['anchor']);

            return $moved;
        });
    }

    /**
     * @return array{
     *     shift: int,
     *     anchor: \DateTimeImmutable,
     *     scheduledEnd: \DateTimeImmutable,
     *     moves: list<array{job: Job, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, recommended: bool, warning: ?string}>,
     *     warnings: list<string>,
     * }|null
     */
    private function plan(Job $done): ?array
    {
        $completedAt = $done->getCompletedAt();
        $scheduledEnd = $done->getEndsAt();
        $startsAt = $done->getStartsAt();
        $assignee = $done->getAssignee();

        if (!$done->isDone() || null === $completedAt || null === $scheduledEnd || null === $startsAt || null === $assignee) {
            return null;
        }

        // Tatsaechliches Ende, auf 15 Minuten aufgerundet; ein Termin belegt mindestens 15 Minuten.
        $anchor = max($this->roundUp($completedAt), $startsAt->modify('+'.self::ROUND_MINUTES.' minutes'));
        $shift = intdiv($anchor->getTimestamp() - $scheduledEnd->getTimestamp(), 60);

        if (abs($shift) < self::ROUND_MINUTES) {
            return null;
        }

        $moves = [];
        $warnings = [];
        $dayEnd = $this->dayEnd($scheduledEnd, $assignee->getDailyMinutes());
        // Frueher fertig: die Kette ab dem geplanten Ende. Spaeter fertig: alles, was jetzt kollidiert.
        $previousEnd = $shift < 0 ? $scheduledEnd : $anchor;

        foreach ($this->jobs->findFollowing($done, min($scheduledEnd, $startsAt)) as $next) {
            $nextStart = $next->getStartsAt();
            $nextEnd = $next->getEndsAt();
            \assert(null !== $nextStart && null !== $nextEnd);

            if ($nextStart < $scheduledEnd) {
                continue; // lag schon vorher parallel – nicht Teil der Kette
            }

            if ($shift < 0) {
                if (intdiv($nextStart->getTimestamp() - $previousEnd->getTimestamp(), 60) > self::CHAIN_GAP_MINUTES) {
                    break; // geplante Luecke bleibt erhalten
                }
                $delta = $shift;
            } else {
                if ($nextStart >= $previousEnd) {
                    break; // kollidiert nicht (mehr) – nichts weiter zu tun
                }
                $delta = intdiv($previousEnd->getTimestamp() - $nextStart->getTimestamp(), 60);
            }

            if ($next->isStarted() || JobStatus::Open !== $next->getStatus()) {
                $warnings[] = sprintf('„%s“ wurde bereits begonnen und wird nicht verschoben.', $next->getTitle());
                break;
            }

            $newStart = $nextStart->modify(sprintf('%+d minutes', $delta));
            $newEnd = $nextEnd->modify(sprintf('%+d minutes', $delta));

            if ($newStart->format('Y-m-d') !== $nextStart->format('Y-m-d')) {
                $warnings[] = sprintf('„%s“ würde auf einen anderen Tag rutschen und bleibt daher stehen.', $next->getTitle());
                break;
            }

            $exclude = [$done->getId(), $next->getId(), ...array_map(static fn (array $m): ?int => $m['job']->getId(), $moves)];
            $conflicts = array_filter(
                $this->jobs->findOverlapping($assignee, $newStart, $newEnd, array_values(array_filter($exclude))),
                // Bei Verspaetung werden spaetere, noch nicht begonnene Termine im naechsten Schritt mitverschoben.
                static fn (Job $other): bool => $shift < 0 || $other->isDone() || $other->isStarted() || $other->getStartsAt() < $newStart,
            );
            if ([] !== $conflicts) {
                $warnings[] = sprintf('„%s“ würde sich mit „%s“ überschneiden und bleibt daher stehen.', $next->getTitle(), reset($conflicts)->getTitle());
                break;
            }

            // Nur warnen, wenn der Termin dadurch spaeter nach Arbeitsschluss endet als bisher.
            $warning = $newEnd > $dayEnd && $newEnd > $nextEnd ? sprintf('Endet nach Arbeitsschluss (%s Uhr).', $dayEnd->format('H:i')) : null;
            $moves[] = ['job' => $next, 'startsAt' => $newStart, 'endsAt' => $newEnd, 'recommended' => null === $warning, 'warning' => $warning];
            $previousEnd = $shift < 0 ? $nextEnd : $newEnd;
        }

        return ['shift' => $shift, 'anchor' => $anchor, 'scheduledEnd' => $scheduledEnd, 'moves' => $moves, 'warnings' => $warnings];
    }

    private function roundUp(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $step = self::ROUND_MINUTES * 60;
        $timestamp = (int) ceil($moment->getTimestamp() / $step) * $step;

        return $moment->setTimestamp($timestamp);
    }

    private function dayEnd(\DateTimeImmutable $day, int $dailyMinutes): \DateTimeImmutable
    {
        // Taegliche Arbeitszeit plus halbe Stunde Mittagspause ab Arbeitsbeginn.
        return $day->setTime(WorkloadCalculator::DAY_START_HOUR, 0)->modify(sprintf('+%d minutes', max(60, $dailyMinutes) + self::LUNCH_BREAK_MINUTES));
    }
}
