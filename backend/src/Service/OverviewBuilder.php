<?php

namespace App\Service;

use App\Entity\Job;
use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Repository\WorkRequestRepository;

/**
 * Daten fuer Dashboard und Seitenleiste der Planung (F9): wann ist welcher
 * Arbeiter frei, offene "Brauche Arbeit"-Anfragen, Terminkonflikte (auch
 * mit Lehrlingen und Abwesenheiten) und Umplanungsvorschlaege.
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
        private readonly AbsenceRepository $absences,
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
            $user = $request->getUser();
            if (null === $user) {
                continue;
            }
            $requests[] = [
                'id' => $request->getId(),
                'user' => $this->describe($user),
                'neededAt' => $request->getNeededAt()->format(\DATE_ATOM),
            ];
        }

        return [
            'generatedAt' => $now->format(\DATE_ATOM),
            'workers' => $workers,
            'requests' => $requests,
            'conflicts' => $this->conflicts($now),
            'absenceConflicts' => $this->absenceConflicts($now),
            'followUps' => $this->followUps($now),
        ];
    }

    /**
     * Ueberschneidende Termine derselben Person (zustaendig oder als Lehrling)
     * in den naechsten Tagen.
     *
     * @return list<array<string, mixed>>
     */
    private function conflicts(\DateTimeImmutable $now): array
    {
        $conflicts = [];
        $jobs = $this->upcomingJobs($now);
        foreach ($jobs as $i => $a) {
            foreach (\array_slice($jobs, $i + 1) as $b) {
                if (!($a->getStartsAt() < $b->getEndsAt() && $b->getStartsAt() < $a->getEndsAt())) {
                    continue;
                }
                foreach ($a->getPeople() as $person) {
                    if (!$b->involves($person)) {
                        continue;
                    }
                    $conflicts[] = [
                        'worker' => $person->getShortName(),
                        'workerId' => $person->getId(),
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
     * Auftraege, die in eine Abwesenheit der zustaendigen Person oder des
     * Lehrlings fallen – sie wurden nicht verschoben und muessen geklaert werden.
     *
     * @return list<array<string, mixed>>
     */
    private function absenceConflicts(\DateTimeImmutable $now): array
    {
        $from = $now->setTime(0, 0);
        $absences = $this->absences->findInRange($from, $from->modify(sprintf('+%d days', self::CONFLICT_DAYS)));
        if ([] === $absences) {
            return [];
        }

        $result = [];
        foreach ($this->upcomingJobs($now) as $job) {
            \assert(null !== $job->getStartsAt() && null !== $job->getEndsAt());
            foreach ($absences as $absence) {
                if ($job->involves($absence->getUser()) && $absence->overlaps($job->getStartsAt(), $job->getEndsAt())) {
                    $result[] = [
                        'worker' => $absence->getUser()->getShortName(),
                        'workerId' => $absence->getUser()->getId(),
                        'job' => ['id' => $job->getId(), 'title' => $job->getTitle(), 'startsAt' => $job->getStartsAt()->format(\DATE_ATOM)],
                        'absence' => [
                            'id' => $absence->getId(),
                            'label' => $absence->getLabel(),
                            'period' => $absence->describePeriod(),
                        ],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<Job>
     */
    private function upcomingJobs(\DateTimeImmutable $now): array
    {
        $from = $now->setTime(0, 0);

        return array_values(array_filter(
            $this->jobs->findInRange($from, $from->modify(sprintf('+%d days', self::CONFLICT_DAYS))),
            static fn (Job $job): bool => [] !== $job->getPeople() && !$job->isDone(),
        ));
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
            if (null !== $entry && $entry->getUser()?->getId() === $user->getId()) {
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
