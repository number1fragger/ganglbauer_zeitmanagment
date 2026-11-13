<?php

namespace App\Controller\Api;

use App\Dto\WorkRequestInput;
use App\Entity\WorkRequest;
use App\Repository\WorkRequestRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
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
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/mine', name: 'api_requests_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        return $this->serialized($this->requests->findOpenFor($this->currentUser()), self::GROUPS);
    }

    /**
     * Pro Arbeiter gibt es nur eine offene Anfrage. Ist schon eine offen, wird
     * sie auf den neuen Zeitpunkt geaendert (200) – das ist dieselbe Anfrage
     * und zaehlt in der Statistik nur einmal. Sonst entsteht eine neue (201).
     * Die Sperre auf den Benutzer verhindert doppelte Anfragen bei Doppelklick.
     */
    #[Route('', name: 'api_requests_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] WorkRequestInput $input): JsonResponse
    {
        \assert(null !== $input->neededAt);
        $user = $this->currentUser();
        $now = $this->clock->now();

        try {
            [$request, $created] = $this->em->wrapInTransaction(function () use ($user, $input, $now): array {
                $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
                $open = $this->requests->findOpenFor($user);
                if (null !== $open) {
                    return [$open->reschedule($input->neededAt, $now), false];
                }

                $request = new WorkRequest($user, $input->neededAt, $now);
                $this->em->persist($request);

                return [$request, true];
            });
        } catch (\DomainException $e) {
            return $this->rejected($e->getMessage());
        }

        return $this->serialized($request, self::GROUPS, $created ? Response::HTTP_CREATED : Response::HTTP_OK);
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
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function fulfil(WorkRequest $request): JsonResponse
    {
        $request->fulfil();
        $this->em->flush();

        return $this->noContent();
    }
}
