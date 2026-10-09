<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Repository\WorkRequestRepository;

/**
 * Daten fuer die Seitenleiste der Planung (F9): Kapazitaet je Arbeiter,
 * offene "Brauche Arbeit"-Anfragen und Warnungen bei Zeitueberschreitung.
 */
class OverviewBuilder
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JobRepository $jobs,
        private readonly WorkRequestRepository $requests,
        private readonly WorkerStatus $status,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $workers = array_map(
            fn (User $user): array => ['user' => $this->describe($user)] + $this->status->of($user, $now),
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

        $warnings = [];
        foreach ($this->jobs->findOpen() as $job) {
            if ($job->isOverrun()) {
                $warnings[] = [
                    'jobId' => $job->getId(),
                    'title' => $job->getTitle(),
                    'worker' => $job->getAssignee()?->getShortName(),
                    'overrunMinutes' => $job->getOverrunMinutes(),
                ];
            }
        }

        return [
            'generatedAt' => $now->format(\DATE_ATOM),
            'workers' => $workers,
            'requests' => $requests,
            'warnings' => $warnings,
        ];
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
