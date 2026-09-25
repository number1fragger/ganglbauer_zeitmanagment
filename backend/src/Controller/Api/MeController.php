<?php

namespace App\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/me')]
class MeController extends AbstractApiController
{
    #[Route('', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->item($this->currentUser(), ['user:read']);
    }

    #[Route('', name: 'api_me_update', methods: ['PATCH'])]
    public function update(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        $user = $this->currentUser();
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

        $violations = $validator->validate($user);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }

        $em->flush();

        return $this->item($user, ['user:read']);
    }

    #[Route('/password', name: 'api_me_password', methods: ['PUT'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $user = $this->currentUser();
        $data = $this->payload($request);

        $current = (string) ($data['currentPassword'] ?? '');
        $new = (string) ($data['newPassword'] ?? '');

        if (!$hasher->isPasswordValid($user, $current)) {
            return $this->json(['title' => 'Das aktuelle Passwort stimmt nicht.'], 400);
        }

        if (mb_strlen($new) < 8) {
            return $this->json(['title' => 'Das neue Passwort braucht mindestens 8 Zeichen.'], 422);
        }

        $user->setPassword($hasher->hashPassword($user, $new));
        $em->flush();

        return $this->json(['status' => 'ok']);
    }
}
