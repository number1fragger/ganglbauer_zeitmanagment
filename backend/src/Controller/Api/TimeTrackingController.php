<?php

namespace App\Controller\Api;

use App\Entity\Job;
use App\Security\JobVoter;
use App\Service\TimeTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zeiterfassung per Start/Stopp – liefert den Ist-Wert fuer A1.
 */
final class TimeTrackingController extends AbstractApiController
{
    private const GROUPS = ['job:read', 'user:ref'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeTracker $tracker,
    ) {
    }

    #[Route('/api/jobs/{id}/start', name: 'api_jobs_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function start(Job $job): JsonResponse
    {
        $this->tracker->start($this->currentUser(), $job);
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    #[Route('/api/time/stop', name: 'api_time_stop', methods: ['POST'])]
    public function stop(): JsonResponse
    {
        $entry = $this->tracker->stop($this->currentUser());
        $this->em->flush();

        return null !== $entry ? $this->serialized($entry->getJob(), self::GROUPS) : $this->noContent();
    }
}
