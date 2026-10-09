<?php

namespace App\Controller\Api;

use App\Dto\ExtendInput;
use App\Dto\JobInput;
use App\Entity\Job;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Security\JobVoter;
use App\Service\JobScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F1–F6: Arbeiten planen, verlaengern und abhaken.
 */
#[Route('/api/jobs')]
final class JobController extends AbstractApiController
{
    private const GROUPS = ['job:read', 'user:ref'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobRepository $jobs,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Kalender: Arbeiten im Zeitraum ?from=&to=. Arbeiter sehen nur die eigenen.
     */
    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->getString('from'), 'from');
        $to = $this->parseDate($request->query->getString('to'), 'to');

        if (null === $from || null === $to || $to <= $from) {
            throw new BadRequestHttpException('Bitte einen gueltigen Zeitraum (from, to) angeben.');
        }

        $assignee = $this->isGranted('ROLE_VORARBEITER') ? null : $this->currentUser();

        return $this->serialized($this->jobs->findInRange($from, $to, $assignee), self::GROUPS);
    }

    /** Arbeiter-Ansicht: meine Arbeiten heute inklusive ueberfaelliger. */
    #[Route('/mine', name: 'api_jobs_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $today = new \DateTimeImmutable('today');

        return $this->serialized($this->jobs->findForWorkerDay($this->currentUser(), $today), self::GROUPS);
    }

    #[Route('/{id}', name: 'api_jobs_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function show(Job $job): JsonResponse
    {
        return $this->serialized($job, self::GROUPS);
    }

    #[Route('', name: 'api_jobs_create', methods: ['POST'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function create(#[MapRequestPayload] JobInput $input): JsonResponse
    {
        $job = new Job();
        $this->apply($job, $input);

        $this->em->persist($job);
        $this->em->flush();

        return $this->serialized($job, self::GROUPS, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_jobs_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function update(Job $job, #[MapRequestPayload] JobInput $input): JsonResponse
    {
        $this->apply($job, $input);
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    #[Route('/{id}', name: 'api_jobs_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function delete(Job $job): JsonResponse
    {
        $this->em->remove($job);
        $this->em->flush();

        return $this->noContent();
    }

    /** F5 – geplante Zeit erhoehen, Folgetermine ruecken nach. */
    #[Route('/{id}/extend', name: 'api_jobs_extend', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function extend(Job $job, JobScheduler $scheduler, #[MapRequestPayload] ExtendInput $input): JsonResponse
    {
        $scheduler->extend($job, $input->minutes);
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    /** F4 – Arbeit abhaken bzw. wieder oeffnen. */
    #[Route('/{id}/complete', name: 'api_jobs_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function complete(Job $job): JsonResponse
    {
        $job->complete();
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    #[Route('/{id}/reopen', name: 'api_jobs_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function reopen(Job $job): JsonResponse
    {
        $job->reopen();
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    private function apply(Job $job, JobInput $input): void
    {
        \assert(null !== $input->startsAt);

        $assignee = null;
        if (null !== $input->assigneeId) {
            $assignee = $this->users->find($input->assigneeId)
                ?? throw new BadRequestHttpException('Diesen Arbeiter gibt es nicht.');
        }

        $job->setTitle($input->title)
            ->setCustomer($input->customer)
            ->setPriority($input->priority)
            ->setAssignee($assignee)
            ->schedule($input->startsAt, $input->plannedMinutes, $input->endsAt);

        if ($input->done && !$job->isDone()) {
            $job->complete();
        } elseif (!$input->done && $job->isDone()) {
            $job->reopen();
        }
    }
}
