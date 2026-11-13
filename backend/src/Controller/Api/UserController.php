<?php

namespace App\Controller\Api;

use App\Dto\UserInput;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Service\UserDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Benutzer & Rechte – nur fuer den Chef (geprueft fuer jede Aktion hier im Backend). */
#[Route('/api/users')]
#[IsGranted('ROLE_CHEF')]
final class UserController extends AbstractApiController
{
    private const GROUPS = ['user:read'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly JobRepository $jobs,
    ) {
    }

    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function list(UserRepository $users): JsonResponse
    {
        return $this->serialized($users->findAllOrdered(), self::GROUPS);
    }

    #[Route('', name: 'api_users_create', methods: ['POST'])]
    public function create(#[MapRequestPayload(validationGroups: ['Default', 'create'])] UserInput $input): JsonResponse
    {
        $user = (new User())->setUsername((string) $input->username);

        return $this->save($user, $input, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_users_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(User $user, #[MapRequestPayload] UserInput $input): JsonResponse
    {
        if ($user === $this->currentUser() && (!$input->active || $input->role !== $user->getRole())) {
            return $this->rejected('Die eigene Rolle und das eigene Konto kann man nicht selbst aendern.');
        }

        // Ein Lehrling, der noch offenen Auftraegen zugeordnet ist, behaelt seine Rolle,
        // sonst stuende dort ein Nicht-Lehrling als Lehrling.
        if (UserRole::Apprentice === $user->getRole() && UserRole::Apprentice !== $input->role) {
            $open = $this->jobs->countOpenAsApprentice($user);
            if ($open > 0) {
                return $this->rejected(sprintf(
                    '%s ist noch bei %d offenen Auftrag/Aufträgen als Lehrling eingetragen. Bitte dort zuerst entfernen, dann die Rolle ändern.',
                    $user->getFullName(),
                    $open,
                ));
            }
        }

        return $this->save($user, $input, Response::HTTP_OK);
    }

    /** Was das endgueltige Loeschen bewirken wuerde – fuer die Sicherheitsabfrage. */
    #[Route('/{id}/deletion-impact', name: 'api_users_deletion_impact', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function deletionImpact(User $user, UserDeletion $deletion): JsonResponse
    {
        return $this->json($deletion->impact($user));
    }

    /**
     * Endgueltig loeschen (nicht nur deaktivieren). Auftraege, Ist-Zeit und
     * Anfragen bleiben als Historie erhalten – siehe UserDeletion.
     */
    #[Route('/{id}', name: 'api_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(User $user, UserDeletion $deletion): JsonResponse
    {
        $deletion->delete($user, $this->currentUser());

        return $this->noContent();
    }

    private function save(User $user, UserInput $input, int $status): JsonResponse
    {
        $user->setFirstName($input->firstName)
            ->setLastName($input->lastName)
            ->setRole($input->role)
            ->setWeeklyHours($input->weeklyHours)
            ->setActive($input->active);

        if (null !== $input->password && '' !== $input->password) {
            $user->setPassword($this->hasher->hashPassword($user, $input->password));
        }

        // Prueft u. a., ob der Benutzername schon vergeben ist.
        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($user, $violations);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $this->serialized($user, self::GROUPS, $status);
    }
}
