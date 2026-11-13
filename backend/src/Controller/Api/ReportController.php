<?php

namespace App\Controller\Api;

use App\Service\ActualTimeReport;
use App\Service\WorkRequestReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Auswertungen fuer Chef und Sekretaerin. Zeitraum als ?from=JJJJ-MM-TT&to=JJJJ-MM-TT
 * (beide inklusive), Standard ist die laufende Woche.
 */
final class ReportController extends AbstractApiController
{
    #[Route('/api/reports/ist-zeit', name: 'api_report_actual_time', methods: ['GET'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function actualTime(Request $request, ActualTimeReport $report): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return $this->json($report->build($from, $to));
    }

    /** Wie oft fragen Mitarbeiter Arbeit an ("Brauche Arbeit")? */
    #[Route('/api/reports/arbeitsanfragen', name: 'api_report_work_requests', methods: ['GET'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function workRequests(Request $request, WorkRequestReport $report): JsonResponse
    {
        [$from, $to] = $this->period($request);

        if ($from->diff($to)->days > 366 * 3) {
            throw new BadRequestHttpException('Der Zeitraum darf höchstens drei Jahre umfassen.');
        }

        return $this->json($report->build($from, $to));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} Beginn inklusive, Ende exklusiv (00:00)
     */
    private function period(Request $request): array
    {
        $from = $this->parseDate($request->query->getString('from'), 'from') ?? new \DateTimeImmutable('monday this week');
        $to = $this->parseDate($request->query->getString('to'), 'to') ?? $from->modify('+6 days');

        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0)->modify('+1 day');

        if ($to <= $from) {
            throw new BadRequestHttpException('Das Ende des Zeitraums liegt vor dem Beginn.');
        }

        return [$from, $to];
    }
}
