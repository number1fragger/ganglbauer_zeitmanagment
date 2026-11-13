<?php

namespace App\Controller\Api;

use App\Util\WorkingHours;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Regulaere Arbeitszeiten fuer das Frontend – die Werte stehen nur in
 * App\Util\WorkingHours, damit es keine zweite, widerspruechliche Kopie gibt.
 */
final class WorkingHoursController extends AbstractApiController
{
    #[Route('/api/working-hours', name: 'api_working_hours', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(WorkingHours::toArray());
    }
}
