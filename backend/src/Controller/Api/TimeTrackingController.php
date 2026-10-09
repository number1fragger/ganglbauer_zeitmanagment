<?php

namespace App\Controller\Api;

use App\Dto\RescheduleInput;
use App\Entity\Job;
use App\Security\JobVoter;
use App\Service\FollowUpPlanner;
use App\Service\TimeTracker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Arbeitsablauf und Ist-Zeiterfassung: Starten, Pausieren, Fortsetzen,
 * Abschliessen und die Umplanung nach frueherem/spaeterem Abschluss.
 *
 * Alle Aktionen sind idempotent bzw. lehnen unzulaessige Wechsel mit
 * 409 ab – doppelte Klicks erzeugen nie doppelte Arbeitszeit.
 */
final class TimeTrackingController extends AbstractApiController
{
    public function __construct(private readonly TimeTracker $tracker)
    {
    }

    /** Arbeit starten bzw. fortsetzen – nur der zugeteilte Arbeiter. */
    #[Route('/api/jobs/{id}/start', name: 'api_jobs_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function start(Job $job): JsonResponse
    {
        return $this->serialized($this->tracker->start($this->currentUser(), $job), JobController::GROUPS);
    }

    /** Laufenden Arbeitsabschnitt beenden; die Arbeit bleibt "in Bearbeitung". */
    #[Route('/api/jobs/{id}/pause', name: 'api_jobs_pause', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function pause(Job $job): JsonResponse
    {
        return $this->serialized($this->tracker->pause($job), JobController::GROUPS);
    }

    /** F4 – Arbeit abschliessen (beendet auch den laufenden Abschnitt). */
    #[Route('/api/jobs/{id}/complete', name: 'api_jobs_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function complete(Job $job): JsonResponse
    {
        return $this->serialized($this->tracker->complete($job), JobController::GROUPS);
    }

    /** Ausdrueckliche Wiedereroeffnung; erfasste Zeit bleibt erhalten. */
    #[Route('/api/jobs/{id}/reopen', name: 'api_jobs_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function reopen(Job $job): JsonResponse
    {
        return $this->serialized($this->tracker->reopen($job), JobController::GROUPS);
    }

    /** Laufende Zeiterfassung des Arbeiters stoppen (egal bei welcher Arbeit). */
    #[Route('/api/time/stop', name: 'api_time_stop', methods: ['POST'])]
    public function stop(): JsonResponse
    {
        $entry = $this->tracker->stop($this->currentUser());

        return null !== $entry ? $this->serialized($entry->getJob(), JobController::GROUPS) : $this->noContent();
    }

    /** Vorschlag: Folgearbeiten nach frueherem/spaeterem Abschluss verschieben. 204 = kein Vorschlag. */
    #[Route('/api/jobs/{id}/follow-up', name: 'api_jobs_follow_up', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function followUp(Job $job, FollowUpPlanner $planner): JsonResponse
    {
        $preview = $planner->preview($job);

        return null === $preview || [] === $preview['moves'] && [] === $preview['warnings']
            ? $this->noContent()
            : $this->json($preview);
    }

    /** Bestaetigten Vorschlag anwenden – Planung ist Sache von Vorarbeiter/Chef. */
    #[Route('/api/jobs/{id}/follow-up', name: 'api_jobs_follow_up_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function applyFollowUp(Job $job, FollowUpPlanner $planner, #[MapRequestPayload] RescheduleInput $input): JsonResponse
    {
        return $this->serialized(['moved' => $planner->apply($job, $input->jobIds), 'job' => $job], JobController::GROUPS);
    }
}
