<?php

namespace App\Controller\Api;

use App\Entity\WorkRequest;
use App\Enum\WorkRequestStatus;
use App\Repository\WorkRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * F7/F8 – "Brauche Arbeit".
 */
#[Route('/api/work-requests')]
class WorkRequestController extends AbstractApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkRequestRepository $requests,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_requests_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $all = $request->query->getBoolean('all');
        $user = $all ? null : $this->currentUser();

        return $this->item(
            $this->requests->findVisible($user, $request->query->getBoolean('includeClosed')),
            ['request:read'],
        );
    }

    #[Route('/mine', name: 'api_requests_mine', methods: ['GET'], priority: 10)]
    public function mine(): JsonResponse
    {
        return $this->item($this->requests->findOpenFor($this->currentUser()), ['request:read']);
    }

    #[Route('', name: 'api_requests_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $user = $this->currentUser();

        // Pro Arbeiter nur eine offene Anforderung – die neue ersetzt die alte.
        $existing = $this->requests->findOpenFor($user);
        if (null !== $existing) {
            $existing->setStatus(WorkRequestStatus::Cancelled);
        }

        $workRequest = new WorkRequest();
        $workRequest->setUser($user);
        $workRequest->setNeededAt(
            $this->parseDate((string) ($data['neededAt'] ?? ''), 'neededAt')
            ?? throw new BadRequestHttpException('Bitte angeben, ab wann du wieder Arbeit brauchst.')
        );
        $workRequest->setNote(isset($data['note']) ? (string) $data['note'] : null);

        $violations = $this->validator->validate($workRequest);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->persist($workRequest);
        $this->em->flush();

        return $this->item($workRequest, ['request:read'], Response::HTTP_CREATED);
    }

    #[Route('/{id}/status', name: 'api_requests_status', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function changeStatus(WorkRequest $workRequest, Request $request): JsonResponse
    {
        $this->denyUnlessOwnerOrAdmin($workRequest);

        $data = $this->payload($request);
        $status = WorkRequestStatus::tryFrom((string) ($data['status'] ?? ''))
            ?? throw new BadRequestHttpException('Unbekannter Status.');

        $workRequest->setStatus($status);
        $this->em->flush();

        return $this->item($workRequest, ['request:read']);
    }

    #[Route('/{id}', name: 'api_requests_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(WorkRequest $workRequest): JsonResponse
    {
        $this->denyUnlessOwnerOrAdmin($workRequest);

        $this->em->remove($workRequest);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function denyUnlessOwnerOrAdmin(WorkRequest $workRequest): void
    {
        if ($workRequest->getUser() !== $this->currentUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Diese Anforderung gehoert einer anderen Person.');
        }
    }
}
