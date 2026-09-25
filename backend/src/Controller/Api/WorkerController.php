<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste der aktiven Arbeiter fuer die Zuteilung einer Arbeit.
 * Anders als /api/users (nur Chef) auch fuer Vorarbeiter erreichbar.
 */
#[IsGranted('ROLE_FOREMAN')]
class WorkerController extends AbstractApiController
{
    #[Route('/api/workers', name: 'api_workers', methods: ['GET'])]
    public function list(UserRepository $users): JsonResponse
    {
        return $this->item($users->findActiveOrdered(), ['user:read']);
    }
}
