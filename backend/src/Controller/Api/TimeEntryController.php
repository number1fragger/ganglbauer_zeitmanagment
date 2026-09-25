<?php

namespace App\Controller\Api;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Repository\JobRepository;
use App\Repository\TimeEntryRepository;
use App\Service\TimeTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Zeiterfassung auf eine Arbeit – liefert den Ist-Wert fuer A1.
 */
#[Route('/api/time-entries')]
class TimeEntryController extends AbstractApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeEntryRepository $entries,
        private readonly JobRepository $jobs,
        private readonly ValidatorInterface $validator,
        private readonly TimeTracker $tracker,
    ) {
    }

    #[Route('', name: 'api_entries_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->get('from'), 'from');
        $to = $this->parseDate($request->query->get('to'), 'to');

        return $this->item(
            $this->entries->findInRange($this->currentUser(), $from, $to?->setTime(23, 59, 59)),
            ['entry:read'],
        );
    }

    #[Route('/running', name: 'api_entries_running', methods: ['GET'], priority: 10)]
    public function running(): JsonResponse
    {
        return $this->item($this->entries->findRunning($this->currentUser()), ['entry:read']);
    }

    #[Route('/start', name: 'api_entries_start', methods: ['POST'], priority: 10)]
    public function start(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        $job = !empty($data['jobId']) ? $this->jobs->find((int) $data['jobId']) : null;
        if (!$job instanceof Job) {
            throw new BadRequestHttpException('Bitte eine Arbeit angeben, an der gearbeitet wird.');
        }

        $entry = $this->tracker->start($this->currentUser(), $job, isset($data['note']) ? (string) $data['note'] : null);

        $violations = $this->validator->validate($entry);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->persist($entry);
        $this->em->flush();

        return $this->item($entry, ['entry:read'], Response::HTTP_CREATED);
    }

    #[Route('/stop', name: 'api_entries_stop', methods: ['POST'], priority: 10)]
    public function stop(): JsonResponse
    {
        $entry = $this->tracker->stopRunning($this->currentUser());

        if (null === $entry) {
            return $this->json(['title' => 'Es laeuft gerade keine Zeiterfassung.'], Response::HTTP_CONFLICT);
        }

        $this->em->flush();

        return $this->item($entry, ['entry:read']);
    }

    #[Route('', name: 'api_entries_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        $job = !empty($data['jobId']) ? $this->jobs->find((int) $data['jobId']) : null;
        if (!$job instanceof Job) {
            throw new BadRequestHttpException('Bitte eine Arbeit angeben.');
        }

        $entry = new TimeEntry();
        $entry->setUser($this->currentUser());
        $entry->setJob($job);
        $this->apply($entry, $data);

        $violations = $this->validator->validate($entry);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->persist($entry);
        $this->em->flush();

        return $this->item($entry, ['entry:read'], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_entries_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(TimeEntry $entry, Request $request): JsonResponse
    {
        $this->denyUnlessOwner($entry);
        $this->apply($entry, $this->payload($request));

        $violations = $this->validator->validate($entry);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->flush();

        return $this->item($entry, ['entry:read']);
    }

    #[Route('/{id}', name: 'api_entries_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(TimeEntry $entry): JsonResponse
    {
        $this->denyUnlessOwner($entry);

        $this->em->remove($entry);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function denyUnlessOwner(TimeEntry $entry): void
    {
        if ($entry->getUser() !== $this->currentUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Dieser Eintrag gehoert einer anderen Person.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(TimeEntry $entry, array $data): void
    {
        if (\array_key_exists('note', $data)) {
            $entry->setNote(null !== $data['note'] ? (string) $data['note'] : null);
        }
        if (!empty($data['startedAt'])) {
            $started = $this->parseDate((string) $data['startedAt'], 'startedAt');
            if (null !== $started) {
                $entry->setStartedAt($started);
            }
        }
        if (\array_key_exists('endedAt', $data)) {
            $entry->setEndedAt($this->parseDate(null !== $data['endedAt'] ? (string) $data['endedAt'] : null, 'endedAt'));
        }
        if (!empty($data['jobId'])) {
            $job = $this->jobs->find((int) $data['jobId']);
            if ($job instanceof Job) {
                $entry->setJob($job);
            }
        }
    }
}
