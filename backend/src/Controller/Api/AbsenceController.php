<?php

namespace App\Controller\Api;

use App\Dto\AbsenceInput;
use App\Entity\Absence;
use App\Entity\User;
use App\Enum\AbsenceType;
use App\Exception\InvalidInputException;
use App\Repository\AbsenceRepository;
use App\Repository\UserRepository;
use App\Service\ScheduleChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Urlaub und persoenliche Termine. Eintragen, aendern und loeschen duerfen
 * nur Chef und Sekretaerin (ROLE_SEKRETAERIN). Alle anderen sehen nur ihre
 * eigenen Abwesenheiten im Kalender – ohne interne Notiz.
 *
 * Bereits eingeplante Auftraege werden nie automatisch verschoben oder
 * geloescht; betroffene Auftraege liefert die Antwort als "conflicts".
 */
#[Route('/api/absences')]
final class AbsenceController extends AbstractApiController
{
    private const GROUPS = ['absence:read', 'user:ref'];
    private const DETAIL_GROUPS = ['absence:read', 'absence:detail', 'user:ref'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceRepository $absences,
        private readonly UserRepository $users,
        private readonly ScheduleChecker $checker,
        private readonly NormalizerInterface $normalizer,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Arten fuer das Formular – neue Arten erscheinen dadurch automatisch. */
    #[Route('/types', name: 'api_absences_types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        return $this->json(AbsenceType::options());
    }

    /** Abwesenheiten im Zeitraum ?from=&to= fuer den Kalender. */
    #[Route('', name: 'api_absences_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->getString('from'), 'from');
        $to = $this->parseDate($request->query->getString('to'), 'to');

        if (null === $from || null === $to || $to <= $from) {
            throw new BadRequestHttpException('Bitte einen gültigen Zeitraum (from, to) angeben.');
        }

        $manager = $this->isGranted('ROLE_SEKRETAERIN');
        $users = $manager ? null : [$this->currentUser()];

        return $this->serialized($this->absences->findInRange($from, $to, $users), $manager ? self::DETAIL_GROUPS : self::GROUPS);
    }

    #[Route('/{id}', name: 'api_absences_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function show(Absence $absence): JsonResponse
    {
        return $this->withConflicts($absence, Response::HTTP_OK);
    }

    #[Route('', name: 'api_absences_create', methods: ['POST'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function create(#[MapRequestPayload] AbsenceInput $input): JsonResponse
    {
        \assert(null !== $input->type);
        $absence = new Absence($this->person($input), $input->type, $this->currentUser(), $this->clock->now());
        $this->apply($absence, $input);

        $this->em->persist($absence);
        $this->em->flush();

        return $this->withConflicts($absence, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_absences_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function update(Absence $absence, #[MapRequestPayload] AbsenceInput $input): JsonResponse
    {
        \assert(null !== $input->type);
        $absence->setUser($this->person($input))->setType($input->type)->touch($this->clock->now());
        $this->apply($absence, $input);
        $this->em->flush();

        return $this->withConflicts($absence, Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'api_absences_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function delete(Absence $absence): JsonResponse
    {
        $this->em->remove($absence);
        $this->em->flush();

        return $this->noContent();
    }

    private function person(AbsenceInput $input): User
    {
        $user = $this->users->find((int) $input->userId) ?? throw new BadRequestHttpException('Diesen Mitarbeiter gibt es nicht.');

        if (!$user->isActive()) {
            throw new InvalidInputException(sprintf('%s ist deaktiviert.', $user->getFullName()));
        }

        return $user;
    }

    private function apply(Absence $absence, AbsenceInput $input): void
    {
        if ($input->allDay) {
            $absence->setDays(
                $this->day((string) $input->startDate),
                $this->day((string) $input->endDate),
            );
        } else {
            \assert(null !== $input->startsAt && null !== $input->endsAt);
            $absence->setPeriod($input->startsAt, $input->endsAt);
        }

        if ($absence->getEndsAt()->getTimestamp() - $absence->getStartsAt()->getTimestamp() > 400 * 86400) {
            throw new InvalidInputException('Eine Abwesenheit darf höchstens etwa ein Jahr dauern.');
        }

        $absence->setNote($input->note);
    }

    private function day(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(date_default_timezone_get()))
            ?: throw new BadRequestHttpException(sprintf('"%s" ist kein gültiges Datum.', $value));
    }

    private function withConflicts(Absence $absence, int $status): JsonResponse
    {
        $data = $this->normalizer->normalize($absence, 'json', ['groups' => self::DETAIL_GROUPS]);
        \assert(\is_array($data));
        $data['conflicts'] = $this->checker->jobsDuring($absence);

        return $this->json($data, $status);
    }
}
