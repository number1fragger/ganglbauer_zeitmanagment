<?php

namespace App\Controller\Api;

use App\Service\ActualTimeReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Auswertung der Ist-Zeit (tatsaechlich geleistete Arbeit). Zeitraum als ?from=JJJJ-MM-TT&to=JJJJ-MM-TT
 * (beide inklusive), Standard ist die laufende Woche.
 */
final class ReportController extends AbstractApiController
{
    #[Route('/api/reports/ist-zeit', name: 'api_report_actual_time', methods: ['GET'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function actualTime(Request $request, ActualTimeReport $report): JsonResponse
    {
        $from = $this->parseDate($request->query->getString('from'), 'from') ?? new \DateTimeImmutable('monday this week');
        $to = $this->parseDate($request->query->getString('to'), 'to') ?? $from->modify('+6 days');

        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0)->modify('+1 day');

        if ($to <= $from) {
            throw new BadRequestHttpException('Das Ende des Zeitraums liegt vor dem Beginn.');
        }

        return $this->json($report->build($from, $to));
    }
}
