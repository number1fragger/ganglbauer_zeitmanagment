<?php

namespace App\Service;

use App\Entity\Job;
use App\Enum\JobStatus;
use App\Repository\JobRepository;
use App\Util\WorkingHours;

/**
 * Verschiebt Termine eines Mitarbeiters automatisch, damit sie sich nicht
 * ueberschneiden (nur wenn paralleles Einplanen ausgeschaltet ist). Der
 * Lehrling spielt dabei keine Rolle.
 *
 * Feste Arbeiten bewegen sich nie: alles, woran schon gearbeitet wird oder
 * was erledigt ist, sowie offene Arbeiten, die vor der bearbeiteten beginnen.
 * Ueberschneidet die bearbeitete Arbeit eine feste, rutscht sie dahinter.
 * Danach werden die folgenden offenen Arbeiten der Reihe nach nach hinten
 * geschoben (Kaskade). Jede Arbeit behaelt ihre Arbeitszeit.
 * Geaendert wird nur im Speicher – der Aufrufer flusht.
 */
class ScheduleShifter
{
    /** Schutz vor Endlosschleifen. */
    private const MAX_ITERATIONS = 500;

    public function __construct(private readonly JobRepository $jobs)
    {
    }

    public function resolve(Job $job): ShiftResult
    {
        $assignee = $job->getAssignee();
        $start = $job->getStartsAt();
        $end = $job->getEndsAt();
        if (null === $assignee || null === $start || null === $end) {
            return new ShiftResult();
        }

        $fixed = [];
        $following = [];
        foreach ($this->jobs->findScheduledFor($assignee, $start, $job) as $other) {
            if (JobStatus::Open !== $other->getStatus() || $other->getStartsAt() < $start) {
                $fixed[] = $other;
            } else {
                $following[] = $other;
            }
        }

        $iterations = 0;

        // 1. Die bearbeitete Arbeit weicht festen Arbeiten aus.
        $selfMoved = false;
        $minutes = max(1, WorkingHours::durationMinutes($start, $end));
        while ($iterations++ < self::MAX_ITERATIONS && null !== ($blocker = $this->overlapping($fixed, $start, $end))) {
            $start = WorkingHours::nextWorkingMoment($blocker->getEndsAt());
            $end = WorkingHours::endFor($start, $minutes);
            $selfMoved = true;
        }
        if ($selfMoved) {
            $job->schedule($start, $end);
        }

        // 2. Folgende offene Arbeiten rutschen nach hinten.
        $moved = [];
        $busyUntil = $end;
        foreach ($following as $other) {
            $otherStart = $other->getStartsAt();
            $otherEnd = $other->getEndsAt();
            \assert(null !== $otherStart && null !== $otherEnd);

            $newStart = $otherStart;
            $newEnd = $otherEnd;
            $otherMinutes = max(1, WorkingHours::durationMinutes($otherStart, $otherEnd));

            while ($iterations++ < self::MAX_ITERATIONS) {
                if ($newStart < $busyUntil) {
                    $newStart = WorkingHours::nextWorkingMoment($busyUntil);
                } elseif (null !== ($blocker = $this->overlapping($fixed, $newStart, $newEnd))) {
                    $newStart = WorkingHours::nextWorkingMoment($blocker->getEndsAt());
                } else {
                    break;
                }
                $newEnd = WorkingHours::endFor($newStart, $otherMinutes);
            }

            if ($newStart != $otherStart) {
                $other->schedule($newStart, $newEnd);
                $moved[] = $other;
            }
            $busyUntil = max($busyUntil, $newEnd);
        }

        return new ShiftResult($moved, $selfMoved);
    }

    /**
     * @param list<Job> $fixed
     */
    private function overlapping(array $fixed, \DateTimeImmutable $start, \DateTimeImmutable $end): ?Job
    {
        foreach ($fixed as $other) {
            if ($other->getStartsAt() < $end && $other->getEndsAt() > $start) {
                return $other;
            }
        }

        return null;
    }
}
