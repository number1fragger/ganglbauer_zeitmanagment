<?php

namespace App\Controller\Api;

use App\Dto\WorkRequestInput;
use App\Entity\WorkRequest;
use App\Repository\WorkRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F7/F8 – "Brauche Arbeit".
 */
#[Route('/api/work-requests')]
final class WorkRequestController extends AbstractApiController
{
    private const GROUPS = ['request:read', 'user:ref'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkRequestRepository $requests,
    ) {
    }

    #[Route('/mine', name: 'api_requests_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        return $this->serialized($this->requests->findOpenFor($this->currentUser()), self::GROUPS);
    }

    /** Pro Arbeiter gibt es nur eine offene Anfrage – eine neue ersetzt die alte. */
    #[Route('', name: 'api_requests_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] WorkRequestInput $input): JsonResponse
    {
        \assert(null !== $input->neededAt);
        $user = $this->currentUser();

        try {
            $request = new WorkRequest($user, $input->neededAt);
        } catch (\DomainException $e) {
            return $this->rejected($e->getMessage());
        }

        $this->requests->findOpenFor($user)?->withdraw();
        $this->em->persist($request);
        $this->em->flush();

        return $this->serialized($request, self::GROUPS, Response::HTTP_CREATED);
    }

    #[Route('/{id}/withdraw', name: 'api_requests_withdraw', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function withdraw(WorkRequest $request): JsonResponse
    {
        if ($request->getUser() !== $this->currentUser()) {
            throw $this->createAccessDeniedException('Diese Anfrage gehoert einer anderen Person.');
        }

        $request->withdraw();
        $this->em->flush();

        return $this->noContent();
    }

    /** Die Planung hat eine Arbeit zugeteilt – die Anfrage ist erledigt. */
    #[Route('/{id}/fulfil', name: 'api_requests_fulfil', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function fulfil(WorkRequest $request): JsonResponse
    {
        $request->fulfil();
        $this->em->flush();

        return $this->noContent();
    }
}
