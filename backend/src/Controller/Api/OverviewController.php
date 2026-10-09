<?php

namespace App\Controller\Api;

use App\Service\OverviewBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F9 – Seitenleiste der Planung: wer ist wie lange ausgelastet,
 * wer braucht wann neue Arbeit.
 */
final class OverviewController extends AbstractApiController
{
    #[Route('/api/overview', name: 'api_overview', methods: ['GET'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function overview(OverviewBuilder $builder): JsonResponse
    {
        return $this->json($builder->build());
    }
}
