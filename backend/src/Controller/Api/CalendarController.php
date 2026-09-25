<?php

namespace App\Controller\Api;

use App\Service\CalendarBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Kalender fuer die Tages-, Wochen- und Monatsansicht.
 *
 * Chef und Vorarbeiter sehen alle Arbeiter, ein Arbeiter nur sich selbst.
 */
class CalendarController extends AbstractApiController
{
    /** Mehr als sechs Wochen braucht keine Ansicht (Monat inkl. Randtage). */
    private const MAX_DAYS = 45;

    #[Route('/api/calendar', name: 'api_calendar', methods: ['GET'])]
    public function calendar(Request $request, CalendarBuilder $builder): JsonResponse
    {
        $from = $this->parseDate($request->query->get('from'), 'from') ?? new \DateTimeImmutable('monday this week');
        $to = $this->parseDate($request->query->get('to'), 'to') ?? $from->modify('+4 days');

        if ($to < $from) {
            throw new BadRequestHttpException('"to" darf nicht vor "from" liegen.');
        }

        if ($from->diff($to)->days > self::MAX_DAYS) {
            throw new BadRequestHttpException(sprintf('Der Zeitraum darf hoechstens %d Tage umfassen.', self::MAX_DAYS));
        }

        $onlyFor = $this->isGranted('ROLE_FOREMAN') ? null : $this->currentUser();

        return $this->json($builder->build($from, $to, $onlyFor));
    }
}
