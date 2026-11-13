<?php

namespace App\Controller\Api;

use App\Dto\JobInput;
use App\Dto\ScheduleInput;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\Priority;
use App\Exception\InvalidInputException;
use App\Exception\WorkflowException;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Security\JobVoter;
use App\Service\PlannedMove;
use App\Service\ScheduleChecker;
use App\Service\ScheduleShifter;
use App\Service\SchedulingSettings;
use App\Service\ShiftResult;
use App\Service\TimeTracker;
use App\Util\LocalTime;
use App\Util\WorkingHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Arbeiten bzw. Aufgaben anlegen, planen, verlaengern und loeschen.
 * Starten/Pausieren/Abschliessen: siehe TimeTrackingController.
 *
 * Schreibende Aufrufe liefern die Arbeit plus "warnings" (Arbeitszeit,
 * Ueberschneidungen, Abwesenheiten). Gespeichert wird trotzdem – die
 * Hinweise sind dazu da, dass nichts unbemerkt bleibt.
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
        private readonly ScheduleChecker $checker,
        private readonly SchedulingSettings $settings,
        private readonly ScheduleShifter $shifter,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    /**
     * Kalender: eingeplante Arbeiten im Zeitraum ?from=&to=. Arbeiter und
     * Lehrlinge sehen nur die eigenen (zustaendig oder als Lehrling dabei).
     */
    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->getString('from'), 'from');
        $to = $this->parseDate($request->query->getString('to'), 'to');

        if (null === $from || null === $to || $to <= $from) {
            throw new BadRequestHttpException('Bitte einen gültigen Zeitraum (from, to) angeben.');
        }

        $assignee = $this->isGranted('ROLE_SEKRETAERIN') ? null : $this->currentUser();

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
        if (!$this->isGranted('ROLE_SEKRETAERIN')) {
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

    /** Details inklusive aller Arbeitsabschnitte und der aktuellen Hinweise. */
    #[Route('/{id}', name: 'api_jobs_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(JobVoter::VIEW, 'job')]
    public function show(Job $job): JsonResponse
    {
        return $this->withWarnings($job, Response::HTTP_OK, self::DETAIL_GROUPS);
    }

    /**
     * Vorschau fuer das Formular: Ende bzw. Dauer nach den Arbeitszeiten und
     * alle Hinweise, bevor gespeichert wird.
     * ?startsAt=&endsAt=|durationMinutes=&plannedMinutes=&assigneeId=&apprenticeId=&jobId=
     */
    #[Route('/check', name: 'api_jobs_check', methods: ['GET'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function check(Request $request): JsonResponse
    {
        $startsAt = $this->parseDate($request->query->getString('startsAt'), 'startsAt')
            ?? throw new BadRequestHttpException('Bitte den Beginn angeben.');
        $endsAt = $this->parseDate($request->query->getString('endsAt'), 'endsAt');
        $duration = $request->query->has('durationMinutes') ? $request->query->getInt('durationMinutes') : null;
        $planned = $request->query->has('plannedMinutes') ? $request->query->getInt('plannedMinutes') : null;

        $endsAt = $this->resolveEnd($startsAt, $endsAt, $duration, $planned);
        if ($endsAt <= $startsAt) {
            throw new InvalidInputException('Das Ende des Termins muss nach dem Beginn liegen.');
        }
        // Wie beim Speichern (Job::schedule): nicht in der Mittagspause beginnen oder enden.
        [$startsAt, $endsAt] = WorkingHours::fitAroundBreaks($startsAt, $endsAt);

        $person = fn (string $key): ?User => '' !== $request->query->getString($key)
            ? ($this->users->find($request->query->getInt($key)) ?? throw new BadRequestHttpException('Diese Person gibt es nicht.'))
            : null;
        $jobId = '' !== $request->query->getString('jobId') ? $request->query->getInt('jobId') : null;

        $assignee = $person('assigneeId');
        $apprentice = $person('apprenticeId');

        // Bei ausgeschaltetem parallelem Einplanen wird verschoben statt gewarnt.
        $plan = (null !== $assignee || null !== $apprentice) && !$this->settings->allowsParallel()
            ? $this->shifter->planFor($startsAt, $endsAt, $assignee, $apprentice, null !== $jobId ? $this->jobs->find($jobId) : null)
            : null;
        $finalStart = $plan?->startsAt ?? $startsAt;
        $finalEnd = $plan?->endsAt ?? $endsAt;

        $warnings = $this->checker->check($finalStart, $finalEnd, $assignee, $apprentice, $jobId);
        if (null !== $plan) {
            $warnings = array_values(array_filter($warnings, static fn (array $w): bool => ScheduleChecker::UEBERSCHNEIDUNG !== $w['type']));
            if ($plan->selfMoved) {
                array_unshift($warnings, ['type' => ScheduleChecker::VERSCHOBEN, 'message' => 'Wird wegen Überschneidung verschoben auf '.$this->describeSlot($finalStart, $finalEnd).'.']);
            }
        }

        return $this->json([
            'startsAt' => $startsAt->format(\DATE_ATOM),
            'endsAt' => $endsAt->format(\DATE_ATOM),
            'workMinutes' => WorkingHours::durationMinutes($startsAt, $endsAt),
            'warnings' => $warnings,
            'shift' => null === $plan ? null : [
                'startsAt' => $finalStart->format(\DATE_ATOM),
                'endsAt' => $finalEnd->format(\DATE_ATOM),
                'moved' => array_map(static fn (PlannedMove $move): array => [
                    'id' => (int) $move->job->getId(),
                    'title' => $move->job->getTitle(),
                    'customer' => $move->job->getCustomer(),
                    'startsAt' => $move->startsAt->format(\DATE_ATOM),
                    'endsAt' => $move->endsAt->format(\DATE_ATOM),
                ], $plan->moved),
            ],
        ]);
    }

    #[Route('', name: 'api_jobs_create', methods: ['POST'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function create(#[MapRequestPayload] JobInput $input, TimeTracker $tracker): JsonResponse
    {
        $job = new Job();
        $this->apply($job, $input);
        $shift = $this->shiftOverlaps($job);

        $this->em->persist($job);
        $this->em->flush();

        if (true === $input->done) {
            $tracker->complete($job);
        }

        return $this->withWarnings($job, Response::HTTP_CREATED, shift: $shift);
    }

    #[Route('/{id}', name: 'api_jobs_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function update(Job $job, #[MapRequestPayload] JobInput $input, TimeTracker $tracker): JsonResponse
    {
        $this->apply($job, $input);
        $shift = $this->shiftOverlaps($job);
        $this->em->flush();

        // Abwaertskompatibel: Abhaken ueber das Formular laeuft ueber dieselbe Logik wie /complete.
        if (true === $input->done && !$job->isDone()) {
            $tracker->complete($job);
        } elseif (false === $input->done && $job->isDone()) {
            $tracker->reopen($job);
        }

        return $this->withWarnings($job, shift: $shift);
    }

    #[Route('/{id}', name: 'api_jobs_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
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
     * Einplanen, Termin verschieben oder verlaengern/verkuerzen (Drag & Drop
     * aus der To-do-Liste, im Kalender oder am Rand eines Termins). Eine
     * Arbeit hat genau einen Termin – erneutes Einplanen verschiebt ihn, es
     * entsteht nie ein zweiter Eintrag. Startet keine Zeit.
     */
    #[Route('/{id}/schedule', name: 'api_jobs_schedule', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
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
            $this->guardTeam($assignee, $job->getApprentice());
            $job->setAssignee($assignee);
        }

        $endsAt = $this->resolveEnd($input->startsAt, $input->endsAt, $input->durationMinutes, $job->getPlannedMinutes());
        $this->guardSchedule($job, $input->startsAt, $endsAt, $input->confirmStartedChange);
        $job->schedule($input->startsAt, $endsAt);
        $shift = $this->shiftOverlaps($job);
        $this->em->flush();

        return $this->withWarnings($job, shift: $shift);
    }

    /**
     * Aus dem Kalender nehmen: die Arbeit kehrt in die To-do-Liste zurueck.
     * Erfasste Ist-Zeit bleibt vollstaendig erhalten.
     */
    #[Route('/{id}/schedule', name: 'api_jobs_unschedule', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
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

        $apprentice = null;
        if (null !== $input->apprenticeId) {
            $apprentice = $this->users->find($input->apprenticeId)
                ?? throw new BadRequestHttpException('Diesen Lehrling gibt es nicht.');
            // Neu zugeordnete Lehrlinge muessen aktiv sein; eine bestehende Zuordnung bleibt erhalten.
            if (!$apprentice->isActive() && !$job->hasApprentice($apprentice)) {
                throw new InvalidInputException(sprintf('%s ist deaktiviert und kann nicht eingeteilt werden.', $apprentice->getFullName()));
            }
        }

        if ($job->isRunning() && $assignee?->getId() !== $job->getAssignee()?->getId()) {
            throw new WorkflowException('Während die Zeiterfassung läuft, kann die Zuständigkeit nicht geändert werden.');
        }

        $this->guardTeam($assignee, $apprentice);

        $endsAt = null !== $input->startsAt ? $this->resolveEnd($input->startsAt, $input->endsAt, $input->durationMinutes, $input->plannedMinutes) : null;
        $this->guardSchedule($job, $input->startsAt, $endsAt, $input->confirmStartedChange);

        $job->setTitle($input->title)
            ->setDescription($input->description)
            ->setCustomer($input->customer)
            ->setPlannedMinutes($input->plannedMinutes)
            ->setPriority($input->priority)
            ->setAssignee($assignee)
            ->setApprentice($apprentice);

        if (null === $input->startsAt) {
            $job->unschedule();
        } else {
            $job->schedule($input->startsAt, $endsAt);
        }
    }

    /** Ende aus Angabe, aus der Dauer (Arbeitszeit), der geplanten Dauer oder Standardlaenge. */
    private function resolveEnd(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, ?int $durationMinutes, ?int $plannedMinutes = null): \DateTimeImmutable
    {
        if (null !== $endsAt) {
            return LocalTime::of($endsAt);
        }

        if (null !== $durationMinutes) {
            if ($durationMinutes <= 0 || $durationMinutes > Job::MAX_WORK_MINUTES) {
                throw new InvalidInputException('Die Dauer ist ungültig.');
            }

            return WorkingHours::endFor($startsAt, $durationMinutes);
        }

        return Job::defaultEndFor($startsAt, $plannedMinutes);
    }

    /**
     * Bei ausgeschaltetem parallelem Einplanen Ueberschneidungen mit anderen
     * Terminen desselben Arbeiters durch Verschieben aufloesen.
     */
    private function shiftOverlaps(Job $job): ShiftResult
    {
        if ($this->settings->allowsParallel() || !$job->isScheduled() || $job->isDone() || [] === $job->getPeople()) {
            return new ShiftResult();
        }

        return $this->shifter->resolve($job);
    }

    /** Lehrling und zustaendige Person muessen verschiedene Personen sein. */
    private function guardTeam(?User $assignee, ?User $apprentice): void
    {
        if (null !== $assignee && null !== $apprentice && $assignee->getId() === $apprentice->getId()) {
            throw new InvalidInputException(sprintf('%s ist bereits zuständig und kann nicht zusätzlich als Lehrling eingetragen werden.', $apprentice->getFullName()));
        }
    }

    /**
     * @param string[]|null $groups
     */
    private function withWarnings(Job $job, int $status = Response::HTTP_OK, ?array $groups = null, ?ShiftResult $shift = null): JsonResponse
    {
        $groups ??= self::GROUPS;
        $data = $this->normalizer->normalize($job, 'json', ['groups' => $groups]);
        \assert(\is_array($data));
        $data['warnings'] = $this->checker->forJob($job);
        if ($shift?->selfMoved) {
            $start = $job->getStartsAt();
            $end = $job->getEndsAt();
            \assert(null !== $start && null !== $end);
            array_unshift($data['warnings'], ['type' => ScheduleChecker::VERSCHOBEN, 'message' => 'Wegen Überschneidung verschoben auf '.$this->describeSlot($start, $end).'.']);
        }
        $data['shifted'] = array_map(
            fn (Job $moved): mixed => $this->normalizer->normalize($moved, 'json', ['groups' => $groups]),
            $shift?->moved ?? [],
        );

        return $this->json($data, $status);
    }

    /** "Mo 28.09. 10:00–11:00" bzw. "Fr 02.10. 13:30–Mo 05.10. 08:00" bei mehrtaegigen Terminen. */
    private function describeSlot(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $days = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $label = static fn (\DateTimeImmutable $d): string => $days[(int) $d->format('N')].' '.$d->format('d.m. H:i');
        $until = $start->format('Y-m-d') === $end->format('Y-m-d') ? $end->format('H:i') : $label($end);

        return $label($start).'–'.$until;
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
