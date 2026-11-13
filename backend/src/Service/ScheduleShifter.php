<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Repository\AbsenceRepository;
use App\Repository\JobRepository;
use App\Util\AbsenceSlot;
use App\Util\Booking;
use App\Util\ScheduleSolver;

/**
 * Verschiebt Termine automatisch, damit sich keine Person (Arbeiter oder
 * Lehrling) mit zwei Arbeiten ueberschneidet – nur wenn paralleles
 * Einplanen ausgeschaltet ist. Die Regeln stehen im ScheduleSolver; diese
 * Klasse laedt Buchungen und Abwesenheiten und wendet den Plan an.
 *
 * Feste Arbeiten bewegen sich nie: alles, woran schon gearbeitet wird oder
 * was erledigt ist, sowie offene Arbeiten, die vor dem Ankerzeitpunkt (Beginn
 * der bearbeiteten Arbeit bzw. jetzt) beginnen.
 *
 * Die plan*-Methoden aendern nichts (Vorschau, Trockenlauf); resolve() und
 * ShiftPlan::applyMoved() aendern nur im Speicher – der Aufrufer flusht.
 */
class ScheduleShifter
{
    /** Abwesenheiten werden so weit vorausgeladen. */
    private const ABSENCE_HORIZON = '+2 years';

    private readonly ScheduleSolver $solver;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly AbsenceRepository $absences,
    ) {
        $this->solver = new ScheduleSolver();
    }

    /** Loest die Ueberschneidungen einer gerade bearbeiteten Arbeit auf (aendert im Speicher). */
    public function resolve(Job $job): ShiftResult
    {
        $start = $job->getStartsAt();
        $end = $job->getEndsAt();
        if (null === $start || null === $end || [] === $job->getPeople()) {
            return new ShiftResult();
        }

        $plan = $this->planFor($start, $end, $job->getAssignee(), $job->getApprentice(), $job);
        if ($plan->selfMoved) {
            \assert(null !== $plan->startsAt && null !== $plan->endsAt);
            $job->schedule($plan->startsAt, $plan->endsAt);
        }
        $plan->applyMoved();

        return new ShiftResult(array_map(static fn (PlannedMove $m): Job => $m->job, $plan->moved), $plan->selfMoved);
    }

    /**
     * Plan fuer eine Arbeit mit dem angegebenen Termin (auch fuer noch nicht
     * gespeicherte, z. B. die Vorschau). $job ist die bestehende Arbeit, falls
     * es sie schon gibt – sie zaehlt dann nicht als fremde Buchung.
     */
    public function planFor(\DateTimeImmutable $start, \DateTimeImmutable $end, ?User $assignee, ?User $apprentice, ?Job $job = null): ShiftPlan
    {
        $persons = $this->personIds([$assignee, $apprentice]);
        if ([] === $persons) {
            return new ShiftPlan(startsAt: $start, endsAt: $end);
        }

        $editedKey = (int) $job?->getId();
        $bookings = [new Booking($editedKey, $start, $end, $persons)];
        $byKey = [];
        foreach ($this->jobs->findScheduledEndingAfter($start) as $other) {
            if ($other === $job || (null !== $job && null !== $job->getId() && $other->getId() === $job->getId())) {
                continue;
            }
            $bookings[] = $this->booking($other, $start);
            $byKey[(int) $other->getId()] = $other;
        }

        $plan = $this->solver->solve($bookings, $this->absenceSlots($start), $editedKey);

        $moved = [];
        foreach ($plan->moves as $key => $booking) {
            if ($key !== $editedKey) {
                $moved[] = new PlannedMove($byKey[$key], $booking->start, $booking->end);
            }
        }
        $self = $plan->moves[$editedKey] ?? null;

        return new ShiftPlan($moved, $plan->editedMoved, $self?->start ?? $start, $self?->end ?? $end);
    }

    /**
     * Plan fuers Neusortieren: alle offenen Arbeiten ab $now, die sich
     * ueberschneiden, rutschen nach hinten.
     */
    public function planResort(\DateTimeImmutable $now): ShiftPlan
    {
        $bookings = [];
        $byKey = [];
        foreach ($this->jobs->findScheduledEndingAfter($now) as $job) {
            if ([] === $job->getPeople()) {
                continue;
            }
            $bookings[] = $this->booking($job, $now);
            $byKey[(int) $job->getId()] = $job;
        }

        $plan = $this->solver->solve($bookings, $this->absenceSlots($now));

        $moved = [];
        foreach ($plan->moves as $key => $booking) {
            $moved[] = new PlannedMove($byKey[$key], $booking->start, $booking->end);
        }

        return new ShiftPlan($moved);
    }

    private function booking(Job $job, \DateTimeImmutable $anchor): Booking
    {
        $start = $job->getStartsAt();
        $end = $job->getEndsAt();
        \assert(null !== $start && null !== $end);

        $fixed = JobStatus::Open !== $job->getStatus() || $start < $anchor;

        return new Booking((int) $job->getId(), $start, $end, $this->personIds($job->getPeople()), $fixed);
    }

    /**
     * @return list<AbsenceSlot>
     */
    private function absenceSlots(\DateTimeImmutable $from): array
    {
        return array_map(
            static fn (Absence $absence): AbsenceSlot => new AbsenceSlot((int) $absence->getUser()->getId(), $absence->getStartsAt(), $absence->getEndsAt()),
            $this->absences->findInRange($from, $from->modify(self::ABSENCE_HORIZON)),
        );
    }

    /**
     * @param array<User|null> $users
     *
     * @return list<int>
     */
    private function personIds(array $users): array
    {
        return array_values(array_map(static fn (User $u): int => (int) $u->getId(), array_filter($users)));
    }
}
