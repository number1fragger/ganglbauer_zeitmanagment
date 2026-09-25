<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->item($this->users->findAllOrdered(), ['user:read']);
    }

    #[Route('', name: 'api_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        $user = new User();
        $user->setEmail((string) ($data['email'] ?? ''));
        $user->setFirstName((string) ($data['firstName'] ?? ''));
        $user->setLastName((string) ($data['lastName'] ?? ''));
        $user->setWeeklyHours((float) ($data['weeklyHours'] ?? 38.5));
        $user->setRoles(array_values(array_filter((array) ($data['roles'] ?? []), 'is_string')));
        $user->setPassword($this->hasher->hashPassword($user, (string) ($data['password'] ?? '')));

        if (mb_strlen((string) ($data['password'] ?? '')) < 8) {
            return $this->json(['title' => 'Das Passwort braucht mindestens 8 Zeichen.'], 422);
        }

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $this->item($user, ['user:read'], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_users_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(User $user, Request $request): JsonResponse
    {
        $data = $this->payload($request);

        if (isset($data['firstName'])) {
            $user->setFirstName((string) $data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastName((string) $data['lastName']);
        }
        if (isset($data['weeklyHours'])) {
            $user->setWeeklyHours((float) $data['weeklyHours']);
        }
        if (isset($data['active'])) {
            $user->setActive((bool) $data['active']);
        }
        if (isset($data['roles'])) {
            $user->setRoles(array_values(array_filter((array) $data['roles'], 'is_string')));
        }

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->flush();

        return $this->item($user, ['user:read']);
    }
}
