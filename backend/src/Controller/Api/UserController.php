<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Benutzer & Rechte – nur fuer den Chef.
 */
#[Route('/api/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractApiController
{
    private const MIN_PASSWORD_LENGTH = 8;

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
        $password = (string) ($data['password'] ?? '');

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->json(['title' => 'Das Passwort braucht mindestens 8 Zeichen.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = new User();
        $user->setEmail((string) ($data['email'] ?? ''));
        $user->setFirstName((string) ($data['firstName'] ?? ''));
        $user->setLastName((string) ($data['lastName'] ?? ''));
        $user->setWeeklyHours((float) ($data['weeklyHours'] ?? 38.5));
        $user->setRole($this->parseRole($data['role'] ?? UserRole::Worker->value));
        $user->setPassword($this->hasher->hashPassword($user, $password));

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
        $isSelf = $user->getId() === $this->currentUser()->getId();

        if (isset($data['email'])) {
            $user->setEmail((string) $data['email']);
        }
        if (isset($data['firstName'])) {
            $user->setFirstName((string) $data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastName((string) $data['lastName']);
        }
        if (isset($data['weeklyHours'])) {
            $user->setWeeklyHours((float) $data['weeklyHours']);
        }

        if (isset($data['role'])) {
            $role = $this->parseRole($data['role']);

            // Sonst koennte sich der letzte Chef selbst aussperren.
            if ($isSelf && UserRole::Admin !== $role) {
                throw new BadRequestHttpException('Die eigene Chef-Rolle kann man sich nicht selbst entziehen.');
            }

            $user->setRole($role);
        }

        if (isset($data['active'])) {
            if ($isSelf && false === (bool) $data['active']) {
                throw new BadRequestHttpException('Das eigene Konto kann nicht deaktiviert werden.');
            }

            $user->setActive((bool) $data['active']);
        }

        // Passwort zuruecksetzen, z. B. wenn ein Arbeiter es vergessen hat.
        if (isset($data['password']) && '' !== $data['password']) {
            if (mb_strlen((string) $data['password']) < self::MIN_PASSWORD_LENGTH) {
                return $this->json(['title' => 'Das Passwort braucht mindestens 8 Zeichen.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $user->setPassword($this->hasher->hashPassword($user, (string) $data['password']));
        }

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $this->em->flush();

        return $this->item($user, ['user:read']);
    }

    private function parseRole(mixed $value): UserRole
    {
        return UserRole::tryFrom((string) $value)
            ?? throw new BadRequestHttpException('Unbekannte Rolle. Erlaubt: ROLE_ADMIN, ROLE_FOREMAN, ROLE_USER.');
    }
}
