<?php

namespace App\Controller\Api;

use App\Repository\JobRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A1 – Soll/Ist-Vergleich pro Arbeiter und pro Arbeit.
 *
 * Nur fuer Chef und Vorarbeiter.
 */
#[IsGranted('ROLE_FOREMAN')]
class ReportController extends AbstractApiController
{
    #[Route('/api/reports/soll-ist', name: 'api_report_soll_ist', methods: ['GET'])]
    public function sollIst(Request $request, JobRepository $jobs): JsonResponse
    {
        $from = ($this->parseDate($request->query->get('from'), 'from') ?? new \DateTimeImmutable('-30 days'))->setTime(0, 0);
        $to = ($this->parseDate($request->query->get('to'), 'to') ?? new \DateTimeImmutable())->setTime(23, 59, 59);

        $perWorker = [];
        $rows = [];

        foreach ($jobs->findCompletedBetween($from, $to) as $job) {
            $assignee = $job->getAssignee();
            $key = $assignee?->getId() ?? 0;
            $planned = $job->getPlannedMinutes();
            $original = $job->getOriginalPlannedMinutes();
            $actual = $job->getActualMinutes();

            $rows[] = [
                'jobId' => $job->getId(),
                'title' => $job->getTitle(),
                'customer' => $job->getCustomer(),
                'worker' => $assignee?->getFullName() ?? 'Nicht zugeteilt',
                'originalPlannedMinutes' => $original,
                'plannedMinutes' => $planned,
                'actualMinutes' => $actual,
                'diffMinutes' => $actual - $planned,
                'completedAt' => $job->getCompletedAt()?->format(\DATE_ATOM),
            ];

            if (!isset($perWorker[$key])) {
                $perWorker[$key] = [
                    'workerId' => $assignee?->getId(),
                    'worker' => $assignee?->getFullName() ?? 'Nicht zugeteilt',
                    'jobs' => 0,
                    'originalPlannedMinutes' => 0,
                    'plannedMinutes' => 0,
                    'actualMinutes' => 0,
                ];
            }

            ++$perWorker[$key]['jobs'];
            $perWorker[$key]['originalPlannedMinutes'] += $original;
            $perWorker[$key]['plannedMinutes'] += $planned;
            $perWorker[$key]['actualMinutes'] += $actual;
        }

        foreach ($perWorker as $key => $entry) {
            $perWorker[$key]['diffMinutes'] = $entry['actualMinutes'] - $entry['plannedMinutes'];
            $perWorker[$key]['accuracyPercent'] = $entry['plannedMinutes'] > 0
                ? (int) round($entry['actualMinutes'] / $entry['plannedMinutes'] * 100)
                : 0;
        }

        usort($perWorker, static fn (array $a, array $b): int => $b['actualMinutes'] <=> $a['actualMinutes']);

        return $this->json([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'perWorker' => array_values($perWorker),
            'jobs' => $rows,
        ]);
    }
}
