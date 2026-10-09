<?php

namespace App\Service;

use App\Entity\Job;
use App\Entity\User;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Repository\WorkRequestRepository;

/**
 * Daten fuer Dashboard und Seitenleiste der Planung (F9): wann ist welcher
 * Arbeiter frei, offene "Brauche Arbeit"-Anfragen, Terminkonflikte und
 * Umplanungsvorschlaege.
 */
class OverviewBuilder
{
    /** So weit voraus werden Terminkonflikte gesucht. */
    private const CONFLICT_DAYS = 14;

    public function __construct(
        private readonly UserRepository $users,
        private readonly JobRepository $jobs,
        private readonly WorkRequestRepository $requests,
        private readonly WorkerStatus $status,
        private readonly FollowUpPlanner $followUps,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $openJobs = $this->jobs->findOpen();

        $workers = array_map(
            fn (User $user): array => ['user' => $this->describe($user)] + $this->status->of($user, $now) + [
                'current' => $this->currentJobOf($user, $openJobs),
            ],
            $this->users->findWorkers(),
        );

        $requests = [];
        foreach ($this->requests->findOpen() as $request) {
            $requests[] = [
                'id' => $request->getId(),
                'user' => $this->describe($request->getUser()),
                'neededAt' => $request->getNeededAt()->format(\DATE_ATOM),
            ];
        }

        return [
            'generatedAt' => $now->format(\DATE_ATOM),
            'workers' => $workers,
            'requests' => $requests,
            'conflicts' => $this->conflicts($now),
            'followUps' => $this->followUps($now),
        ];
    }

    /**
     * Ueberschneidende Termine desselben Arbeiters in den naechsten Tagen.
     *
     * @return list<array<string, mixed>>
     */
    private function conflicts(\DateTimeImmutable $now): array
    {
        $from = $now->setTime(0, 0);
        $jobs = array_filter(
            $this->jobs->findInRange($from, $from->modify(sprintf('+%d days', self::CONFLICT_DAYS))),
            static fn (Job $job): bool => null !== $job->getAssignee() && !$job->isDone(),
        );

        $conflicts = [];
        $jobs = array_values($jobs);
        foreach ($jobs as $i => $a) {
            foreach (\array_slice($jobs, $i + 1) as $b) {
                if ($a->getAssignee()?->getId() === $b->getAssignee()?->getId()
                    && $a->getStartsAt() < $b->getEndsAt() && $b->getStartsAt() < $a->getEndsAt()) {
                    $conflicts[] = [
                        'worker' => $a->getAssignee()?->getShortName(),
                        'jobs' => [
                            ['id' => $a->getId(), 'title' => $a->getTitle(), 'startsAt' => $a->getStartsAt()?->format(\DATE_ATOM)],
                            ['id' => $b->getId(), 'title' => $b->getTitle(), 'startsAt' => $b->getStartsAt()?->format(\DATE_ATOM)],
                        ],
                        'from' => max($a->getStartsAt(), $b->getStartsAt())?->format(\DATE_ATOM),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Heute abgeschlossene Arbeiten, nach denen Folgetermine verschoben
     * werden koennten.
     *
     * @return list<array<string, mixed>>
     */
    private function followUps(\DateTimeImmutable $now): array
    {
        $result = [];
        foreach ($this->jobs->findCompletedBetween($now->setTime(0, 0), $now->setTime(0, 0)->modify('+1 day')) as $job) {
            $preview = $this->followUps->preview($job);
            if (null !== $preview && [] !== $preview['moves']) {
                $result[] = $preview + ['title' => $job->getTitle(), 'worker' => $job->getAssignee()?->getShortName()];
            }
        }

        return $result;
    }

    /**
     * @param Job[] $openJobs
     *
     * @return array<string, mixed>|null
     */
    private function currentJobOf(User $user, array $openJobs): ?array
    {
        foreach ($openJobs as $job) {
            $entry = $job->getRunningEntry();
            if (null !== $entry && $entry->getUser()->getId() === $user->getId()) {
                return [
                    'jobId' => $job->getId(),
                    'title' => $job->getTitle(),
                    'runningSince' => $entry->getStartedAt()->format(\DATE_ATOM),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(User $user): array
    {
        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'shortName' => $user->getShortName(),
            'initials' => $user->getInitials(),
            'role' => $user->getRole()->value,
            'weeklyHours' => $user->getWeeklyHours(),
        ];
    }
}
