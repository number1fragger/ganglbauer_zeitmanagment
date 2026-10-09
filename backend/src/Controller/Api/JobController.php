<?php

namespace App\Controller\Api;

use App\Dto\JobInput;
use App\Dto\ScheduleInput;
use App\Entity\Job;
use App\Enum\Priority;
use App\Exception\WorkflowException;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Security\JobVoter;
use App\Service\TimeTracker;
use App\Util\LocalTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Arbeiten bzw. Aufgaben anlegen, planen, verlaengern und loeschen.
 * Starten/Pausieren/Abschliessen: siehe JobWorkflowController.
 */
#[Route('/api/jobs')]
final class JobController extends AbstractApiController
{
    public const GROUPS = ['job:read', 'user:ref'];
    public const DETAIL_GROUPS = ['job:read', 'job:entries', 'user:ref'];

    /** Abgeschlossene Aufgaben bleiben so viele Tage in der Aufgabenverwaltung sichtbar. */
    private const BOARD_DONE_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobRepository $jobs,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Kalender: eingeplante Arbeiten im Zeitraum ?from=&to=. Arbeiter sehen nur die eigenen.
     */
    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->getString('from'), 'from');
        $to = $this->parseDate($request->query->getString('to'), 'to');

        if (null === $from || null === $to || $to <= $from) {
            throw new BadRequestHttpException('Bitte einen gültigen Zeitraum (from, to) angeben.');
        }

        $assignee = $this->isGranted('ROLE_VORARBEITER') ? null : $this->currentUser();

        return $this->serialized($this->jobs->findInRange($from, $to, $assignee), self::GROUPS);
    }

    /**
     * Aufgabenverwaltung: alle offenen und laufenden Arbeiten (mit und ohne
     * Termin) plus kuerzlich abgeschlossene. Filter: ?assignee=&priority=&q=&doneDays=
     */
    #[Route('/board', name: 'api_jobs_board', methods: ['GET'])]
    public function board(Request $request): JsonResponse
    {
        $assignee = null;
        if (!$this->isGranted('ROLE_VORARBEITER')) {
            $assignee = $this->currentUser();
        } elseif ($request->query->has('assignee') && '' !== $request->query->getString('assignee')) {
            $assignee = $this->users->find($request->query->getInt('assignee'))
                ?? throw new BadRequestHttpException('Diesen Arbeiter gibt es nicht.');
        }

        $priority = Priority::tryFrom($request->query->getString('priority'));
        $doneDays = max(0, min(365, $request->query->getInt('doneDays', self::BOARD_DONE_DAYS)));
        $doneSince = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $doneDays));

        return $this->serialized(
            $this->jobs->findBoard($doneSince, $assignee, $priority, $request->query->getString('q')),
            self::GROUPS,
        );
    }

    /** "Meine Arbeiten": alles Offene des angemeldeten Arbeiters plus heute Erledigtes. */
    #[Route('/mine', name: 'api_jobs_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        return $this->serialized($this->jobs->findMine($this->currentUser(), new \DateTimeImmutable('today')), self::GROUPS);
    }

    /** Details inklusive aller Arbeitsabschnitte. */
    #[Route('/{id}', name: 'api_jobs_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::WORK, 'job')]
    public function show(Job $job): JsonResponse
    {
        return $this->serialized($job, self::DETAIL_GROUPS);
    }

    #[Route('', name: 'api_jobs_create', methods: ['POST'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function create(#[MapRequestPayload] JobInput $input, TimeTracker $tracker): JsonResponse
    {
        $job = new Job();
        $this->apply($job, $input);

        $this->em->persist($job);
        $this->em->flush();

        if (true === $input->done) {
            $tracker->complete($job);
        }

        return $this->serialized($job, self::GROUPS, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_jobs_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function update(Job $job, #[MapRequestPayload] JobInput $input, TimeTracker $tracker): JsonResponse
    {
        $this->apply($job, $input);
        $this->em->flush();

        // Abwaertskompatibel: Abhaken ueber das Formular laeuft ueber dieselbe Logik wie /complete.
        if (true === $input->done && !$job->isDone()) {
            $tracker->complete($job);
        } elseif (false === $input->done && $job->isDone()) {
            $tracker->reopen($job);
        }

        return $this->serialized($job, self::GROUPS);
    }

    #[Route('/{id}', name: 'api_jobs_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function delete(Job $job): JsonResponse
    {
        if ($job->isRunning()) {
            throw new WorkflowException('An dieser Arbeit wird gerade gearbeitet. Bitte zuerst pausieren oder abschließen.');
        }

        $this->em->remove($job);
        $this->em->flush();

        return $this->noContent();
    }

    /**
     * Einplanen bzw. Termin verschieben (Drag & Drop aus der To-do-Liste oder
     * im Kalender). Eine Arbeit hat genau einen Termin – erneutes Einplanen
     * verschiebt ihn, es entsteht nie ein zweiter Eintrag. Startet keine Zeit.
     */
    #[Route('/{id}/schedule', name: 'api_jobs_schedule', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function schedule(Job $job, #[MapRequestPayload] ScheduleInput $input): JsonResponse
    {
        \assert(null !== $input->startsAt);

        if ($input->changeAssignee) {
            $assignee = null !== $input->assigneeId
                ? ($this->users->find($input->assigneeId) ?? throw new BadRequestHttpException('Diesen Arbeiter gibt es nicht.'))
                : null;
            if ($job->isRunning() && $assignee?->getId() !== $job->getAssignee()?->getId()) {
                throw new WorkflowException('Während die Zeiterfassung läuft, kann die Zuständigkeit nicht geändert werden.');
            }
            $job->setAssignee($assignee);
        }

        $this->guardSchedule($job, $input->startsAt, $input->endsAt ?? $input->startsAt->modify(sprintf('+%d minutes', Job::DEFAULT_SLOT_MINUTES)), $input->confirmStartedChange);
        $job->schedule($input->startsAt, $input->endsAt);
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    /**
     * Aus dem Kalender nehmen: die Arbeit kehrt in die To-do-Liste zurueck.
     * Erfasste Ist-Zeit bleibt vollstaendig erhalten.
     */
    #[Route('/{id}/schedule', name: 'api_jobs_unschedule', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_VORARBEITER')]
    public function unschedule(Job $job): JsonResponse
    {
        if ($job->isDone()) {
            throw new WorkflowException('Der Termin einer abgeschlossenen Arbeit kann nicht mehr geändert werden. Bitte die Arbeit zuerst wieder öffnen.');
        }

        $job->unschedule();
        $this->em->flush();

        return $this->serialized($job, self::GROUPS);
    }

    private function apply(Job $job, JobInput $input): void
    {
        $assignee = null;
        if (null !== $input->assigneeId) {
            $assignee = $this->users->find($input->assigneeId)
                ?? throw new BadRequestHttpException('Diesen Arbeiter gibt es nicht.');
        }

        if ($job->isRunning() && $assignee?->getId() !== $job->getAssignee()?->getId()) {
            throw new WorkflowException('Während die Zeiterfassung läuft, kann die Zuständigkeit nicht geändert werden.');
        }

        $this->guardSchedule($job, $input->startsAt, $input->endsAt, $input->confirmStartedChange);

        $job->setTitle($input->title)
            ->setDescription($input->description)
            ->setCustomer($input->customer)
            ->setPriority($input->priority)
            ->setAssignee($assignee);

        if (null === $input->startsAt) {
            $job->unschedule();
        } else {
            $job->schedule($input->startsAt, $input->endsAt);
        }
    }

    /**
     * Termine abgeschlossener Arbeiten sind gesperrt; Termine begonnener
     * Arbeiten nur mit ausdruecklicher Bestaetigung aenderbar – damit nichts
     * versehentlich (z. B. per Drag & Drop) verschoben wird.
     */
    private function guardSchedule(Job $job, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, bool $confirmed): void
    {
        $same = static fn (?\DateTimeImmutable $a, ?\DateTimeImmutable $b): bool => (null === $a && null === $b)
            || (null !== $a && null !== $b && LocalTime::of($a) == $b);

        if (null === $endsAt && null !== $startsAt) {
            $endsAt = $startsAt->modify(sprintf('+%d minutes', Job::DEFAULT_SLOT_MINUTES));
        }

        if ($same($startsAt, $job->getStartsAt()) && $same($endsAt, $job->getEndsAt())) {
            return;
        }

        if ($job->isDone()) {
            throw new WorkflowException('Der Termin einer abgeschlossenen Arbeit kann nicht mehr geändert werden. Bitte die Arbeit zuerst wieder öffnen.');
        }

        if ($job->isStarted() && !$confirmed) {
            throw new WorkflowException('Diese Arbeit wurde bereits begonnen. Den Termin bitte bewusst im Dialog ändern.');
        }
    }
}
