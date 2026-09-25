<?php

namespace App\Controller\Api;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Enum\Priority;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Security\Voter\JobVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * F1–F6: Arbeiten anlegen, priorisieren, verlaengern und abhaken.
 *
 * Planen (anlegen, bearbeiten, loeschen) duerfen Chef und Vorarbeiter.
 * Arbeiter sehen nur ihre eigenen Arbeiten und duerfen dort Zeit
 * erhoehen und abhaken – siehe JobVoter.
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
        if (!$this->isGranted('ROLE_FOREMAN')) {
            // Arbeiter sehen immer nur die eigenen Arbeiten.
            $assignee = $this->currentUser();
        } elseif ('me' === $request->query->get('assignee')) {
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
    #[IsGranted(JobVoter::VIEW, 'job')]
    public function show(Job $job): JsonResponse
    {
        return $this->item($job, ['job:read']);
    }

    #[Route('', name: 'api_jobs_create', methods: ['POST'])]
    #[IsGranted('ROLE_FOREMAN', message: 'Nur Chef und Vorarbeiter duerfen Arbeiten anlegen.')]
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
    #[IsGranted(JobVoter::EDIT, 'job', message: 'Nur Chef und Vorarbeiter duerfen Arbeiten bearbeiten.')]
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

    /** Drag & Drop im Kalender: neuer Beginn und optional ein anderer Arbeiter. */
    #[Route('/{id}/move', name: 'api_jobs_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::EDIT, 'job', message: 'Nur Chef und Vorarbeiter duerfen Arbeiten verschieben.')]
    public function move(Job $job, Request $request): JsonResponse
    {
        $data = $this->payload($request);

        if (JobStatus::Done === $job->getStatus()) {
            throw new BadRequestHttpException('Erledigte Arbeiten koennen nicht verschoben werden.');
        }

        $job->moveTo(
            $this->parseDate(isset($data['startsAt']) ? (string) $data['startsAt'] : null, 'startsAt')
            ?? throw new BadRequestHttpException('Bitte den neuen Beginn angeben.')
        );

        if (\array_key_exists('assigneeId', $data)) {
            $job->setAssignee($this->assigneeFrom($data['assigneeId']));
        }

        $violations = $this->validator->validate($job);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->flush();

        return $this->item($job, ['job:read']);
    }

    /** F5 – die geplante Arbeitszeit nachtraeglich erhoehen. */
    #[Route('/{id}/extend', name: 'api_jobs_extend', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
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
    #[IsGranted(JobVoter::WORK, 'job')]
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
    #[IsGranted(JobVoter::DELETE, 'job', message: 'Nur Chef und Vorarbeiter duerfen Arbeiten loeschen.')]
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
            $job->setAssignee($this->assigneeFrom($data['assigneeId']));
        }
    }

    private function assigneeFrom(mixed $id): ?User
    {
        if (empty($id)) {
            return null;
        }

        $user = $this->users->find((int) $id);
        if (null === $user || !$user->isActive()) {
            throw new BadRequestHttpException('Diesen Arbeiter gibt es nicht oder er ist deaktiviert.');
        }

        return $user;
    }
}
