<?php

namespace App\Service;

use App\Entity\Job;
use App\Repository\JobRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\UserRepository;
use App\Repository\WorkRequestRepository;

/**
 * Baut die Startseite der App (F9): Wer ist womit beschaeftigt,
 * wie lange noch, und wer hat schon neue Arbeit angefordert.
 */
class OverviewBuilder
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JobRepository $jobs,
        private readonly TimeEntryRepository $entries,
        private readonly WorkRequestRepository $requests,
        private readonly WorkloadCalculator $workload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $now = new \DateTimeImmutable();
        $workers = [];

        foreach ($this->users->findAllOrdered() as $user) {
            if (!$user->isActive()) {
                continue;
            }

            $openJobs = $this->jobs->findOpenFor($user);

            $remaining = 0;
            $overrunCount = 0;
            foreach ($openJobs as $job) {
                $remaining += $job->getRemainingMinutes();
                if ($job->isOverrun()) {
                    ++$overrunCount;
                }
            }

            $running = $this->entries->findRunning($user);
            $request = $this->requests->findOpenFor($user);
            $availableFrom = $this->workload->estimateAvailableFrom($user, $remaining, $now);

            $workers[] = [
                'user' => [
                    'id' => $user->getId(),
                    'fullName' => $user->getFullName(),
                    'dailyMinutes' => $user->getDailyMinutes(),
                ],
                'openJobs' => \count($openJobs),
                'remainingMinutes' => $remaining,
                'overrunJobs' => $overrunCount,
                'availableFrom' => $availableFrom->format(\DATE_ATOM),
                'availableToday' => $availableFrom->format('Y-m-d') === $now->format('Y-m-d'),
                'currentJob' => null !== $running ? $this->describeJob($running->getJob()) : null,
                'nextJob' => $this->describeJob($this->pickNext($openJobs)),
                'workRequest' => null !== $request ? [
                    'id' => $request->getId(),
                    'neededAt' => $request->getNeededAt()->format(\DATE_ATOM),
                    'note' => $request->getNote(),
                ] : null,
            ];
        }

        // Wer am ehesten frei ist, steht oben – dafuer wird geplant.
        usort($workers, static fn (array $a, array $b): int => strcmp($a['availableFrom'], $b['availableFrom']));

        return [
            'generatedAt' => $now->format(\DATE_ATOM),
            'workers' => $workers,
            'totals' => [
                'workers' => \count($workers),
                'openJobs' => array_sum(array_column($workers, 'openJobs')),
                'remainingMinutes' => array_sum(array_column($workers, 'remainingMinutes')),
                'openRequests' => \count(array_filter($workers, static fn (array $w): bool => null !== $w['workRequest'])),
            ],
        ];
    }

    /**
     * @param Job[] $jobs
     */
    private function pickNext(array $jobs): ?Job
    {
        $next = null;

        foreach ($jobs as $job) {
            if (null === $next) {
                $next = $job;
                continue;
            }

            if ($job->getPriority()->weight() > $next->getPriority()->weight()) {
                $next = $job;
            }
        }

        return $next;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeJob(?Job $job): ?array
    {
        if (null === $job) {
            return null;
        }

        return [
            'id' => $job->getId(),
            'title' => $job->getTitle(),
            'customer' => $job->getCustomer(),
            'priority' => $job->getPriority()->value,
            'plannedMinutes' => $job->getPlannedMinutes(),
            'actualMinutes' => $job->getActualMinutes(),
            'remainingMinutes' => $job->getRemainingMinutes(),
            'overrun' => $job->isOverrun(),
            'dueAt' => $job->getDueAt()?->format(\DATE_ATOM),
        ];
    }
}
