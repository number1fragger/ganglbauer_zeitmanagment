<?php

namespace App\Service;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Repository\JobRepository;
use App\Repository\UserRepository;

/**
 * Liefert die Daten fuer die Kalenderansichten (Tag, Woche, Monat).
 *
 * Jede eingeplante Arbeit wird ab ihrem Beginn auf die Arbeitszeit des
 * zugeteilten Arbeiters gelegt. Dauert sie laenger als ein Arbeitstag,
 * entstehen mehrere Abschnitte ("Teil 1/2", "Teil 2/2").
 */
class CalendarBuilder
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly UserRepository $users,
        private readonly WorkloadCalculator $workload,
    ) {
    }

    /**
     * @param User|null $onlyFor Arbeiter sehen nur sich selbst, null = alle
     *
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $onlyFor = null): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(23, 59, 59);

        $workers = null !== $onlyFor ? [$onlyFor] : $this->users->findActiveOrdered();

        $segments = [];
        foreach ($this->jobs->findScheduledAround($from, $to, $onlyFor) as $job) {
            foreach ($this->segmentsFor($job, $from, $to) as $segment) {
                $segments[] = $segment;
            }
        }

        usort($segments, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'dayStartHour' => WorkloadCalculator::DAY_START_HOUR,
            'workers' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'initials' => $user->getInitials(),
                'dailyMinutes' => $user->getDailyMinutes(),
            ], $workers),
            'segments' => $segments,
            'unscheduled' => array_map(fn (Job $job): array => $this->describe($job), $this->jobs->findUnscheduled($onlyFor)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function segmentsFor(Job $job, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $assignee = $job->getAssignee();
        $start = $job->getStartsAt();

        if (null === $assignee || null === $start) {
            return [];
        }

        // Ist die Arbeit schon ueberzogen, zaehlt die tatsaechliche Zeit –
        // sonst wuerde der Kalender den Arbeiter zu frueh als frei zeigen.
        $minutes = max($job->getPlannedMinutes(), $job->getActualMinutes());
        $blocks = $this->workload->splitIntoWorkingBlocks($assignee, $start, $minutes);
        $parts = \count($blocks);
        $plannedEnd = $blocks[$parts - 1]['end'] ?? $start;
        $late = null !== $job->getDueAt() && $plannedEnd > $job->getDueAt();

        // Der Plan ist vorbei, die Arbeit aber noch offen: Der Arbeiter
        // haengt hinterher, alles Folgende verschiebt sich.
        $behind = JobStatus::Done !== $job->getStatus() && $plannedEnd < new \DateTimeImmutable();

        $result = [];
        foreach ($blocks as $index => $block) {
            if ($block['end'] < $from || $block['start'] > $to) {
                continue;
            }

            $result[] = $this->describe($job) + [
                'workerId' => $assignee->getId(),
                'start' => $block['start']->format(\DATE_ATOM),
                'end' => $block['end']->format(\DATE_ATOM),
                'part' => $index + 1,
                'parts' => $parts,
                'plannedEnd' => $plannedEnd->format(\DATE_ATOM),
                'late' => $late,
                'behind' => $behind,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Job $job): array
    {
        return [
            'jobId' => $job->getId(),
            'title' => $job->getTitle(),
            'customer' => $job->getCustomer(),
            'priority' => $job->getPriority()->value,
            'status' => $job->getStatus()->value,
            'done' => JobStatus::Done === $job->getStatus(),
            'running' => $job->isRunning(),
            'overrun' => $job->isOverrun(),
            'plannedMinutes' => $job->getPlannedMinutes(),
            'actualMinutes' => $job->getActualMinutes(),
            'assignee' => null !== $job->getAssignee() ? [
                'id' => $job->getAssignee()->getId(),
                'fullName' => $job->getAssignee()->getFullName(),
                'initials' => $job->getAssignee()->getInitials(),
            ] : null,
            'startsAt' => $job->getStartsAt()?->format(\DATE_ATOM),
            'dueAt' => $job->getDueAt()?->format(\DATE_ATOM),
        ];
    }
}
