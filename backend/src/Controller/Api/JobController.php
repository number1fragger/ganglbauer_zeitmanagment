<?php

namespace App\Controller\Api;

use App\Entity\Job;
use App\Enum\JobStatus;
use App\Enum\Priority;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * F1–F6: Arbeiten anlegen, priorisieren, verlaengern und abhaken.
 */
#[Route('/api/jobs')]
class JobController extends AbstractApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobRepository $jobs,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $assignee = null;
        if ('me' === $request->query->get('assignee')) {
            $assignee = $this->currentUser();
        } elseif (null !== $request->query->get('assignee')) {
            $assignee = $this->users->find((int) $request->query->get('assignee'));
        }

        $status = null;
        if (null !== $request->query->get('status')) {
            $status = JobStatus::tryFrom((string) $request->query->get('status'))
                ?? throw new BadRequestHttpException('Unbekannter Status.');
        }

        $jobs = $this->jobs->findFiltered($assignee, $status, $request->query->getBoolean('includeDone'));

        return $this->item($jobs, ['job:read']);
    }

    #[Route('/{id}', name: 'api_jobs_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Job $job): JsonResponse
    {
        return $this->item($job, ['job:read']);
    }

    #[Route('', name: 'api_jobs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $job = new Job();
        $this->apply($job, $this->payload($request));

        $violations = $this->validator->validate($job);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->persist($job);
        $this->em->flush();

        return $this->item($job, ['job:read'], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_jobs_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(Job $job, Request $request): JsonResponse
    {
        $this->apply($job, $this->payload($request));

        $violations = $this->validator->validate($job);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->flush();

        return $this->item($job, ['job:read']);
    }

    /** F5 – die geplante Arbeitszeit nachtraeglich erhoehen. */
    #[Route('/{id}/extend', name: 'api_jobs_extend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function extend(Job $job, Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $minutes = (int) ($data['minutes'] ?? 0);

        if ($minutes <= 0) {
            throw new BadRequestHttpException('Bitte angeben, um wie viele Minuten verlaengert wird.');
        }

        $job->extendBy($minutes);
        $this->em->flush();

        return $this->item($job, ['job:read']);
    }

    /** F4 – Arbeit als erledigt abhaken (oder wieder oeffnen). */
    #[Route('/{id}/complete', name: 'api_jobs_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(Job $job, Request $request): JsonResponse
    {
        $data = $this->payload($request);

        if (false === ($data['done'] ?? true)) {
            $job->reopen();
        } else {
            $job->complete();
        }

        $this->em->flush();

        return $this->item($job, ['job:read']);
    }

    #[Route('/{id}', name: 'api_jobs_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Job $job): JsonResponse
    {
        $this->em->remove($job);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(Job $job, array $data): void
    {
        if (isset($data['title'])) {
            $job->setTitle((string) $data['title']);
        }
        if (\array_key_exists('description', $data)) {
            $job->setDescription(null !== $data['description'] ? (string) $data['description'] : null);
        }
        if (\array_key_exists('customer', $data)) {
            $job->setCustomer(null !== $data['customer'] ? (string) $data['customer'] : null);
        }
        if (isset($data['priority'])) {
            $job->setPriority(Priority::tryFrom((string) $data['priority'])
                ?? throw new BadRequestHttpException('Unbekannte Prioritaet.'));
        }
        if (isset($data['status'])) {
            $job->setStatus(JobStatus::tryFrom((string) $data['status'])
                ?? throw new BadRequestHttpException('Unbekannter Status.'));
        }
        if (isset($data['plannedMinutes'])) {
            $job->setPlannedMinutes((int) $data['plannedMinutes']);
        }
        if (\array_key_exists('startsAt', $data)) {
            $job->setStartsAt($this->parseDate(null !== $data['startsAt'] ? (string) $data['startsAt'] : null, 'startsAt'));
        }
        if (\array_key_exists('dueAt', $data)) {
            $job->setDueAt($this->parseDate(null !== $data['dueAt'] ? (string) $data['dueAt'] : null, 'dueAt'));
        }
        if (\array_key_exists('assigneeId', $data)) {
            $job->setAssignee(!empty($data['assigneeId']) ? $this->users->find((int) $data['assigneeId']) : null);
        }
    }
}
