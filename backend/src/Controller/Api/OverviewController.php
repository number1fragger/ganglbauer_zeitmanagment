<?php

namespace App\Controller\Api;

use App\Service\OverviewBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * F9 – die Startseite: wer ist wie lange ausgelastet, wer wird wann frei.
 */
class OverviewController extends AbstractApiController
{
    #[Route('/api/overview', name: 'api_overview', methods: ['GET'])]
    public function overview(OverviewBuilder $builder): JsonResponse
    {
        return $this->json($builder->build());
    }
}
