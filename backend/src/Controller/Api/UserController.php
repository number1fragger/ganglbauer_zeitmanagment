<?php

namespace App\Controller\Api;

use App\Dto\UserInput;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Benutzer & Rechte – nur fuer den Chef. */
#[Route('/api/users')]
#[IsGranted('ROLE_CHEF')]
final class UserController extends AbstractApiController
{
    private const GROUPS = ['user:read'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
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

        return $this->save($user, $input, Response::HTTP_OK);
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
