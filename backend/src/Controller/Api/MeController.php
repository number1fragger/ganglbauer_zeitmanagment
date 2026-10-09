<?php

namespace App\Controller\Api;

use App\Dto\PasswordInput;
use App\Dto\PreferencesInput;
use App\Service\WorkerStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
final class MeController extends AbstractApiController
{
    #[Route('', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->serialized($this->currentUser(), ['user:read']);
    }

    /** Restarbeit und voraussichtliches Ende fuer die Arbeiter-Ansicht. */
    #[Route('/status', name: 'api_me_status', methods: ['GET'])]
    public function status(WorkerStatus $status): JsonResponse
    {
        return $this->json($status->of($this->currentUser(), new \DateTimeImmutable()));
    }

    /** Persoenliche Einstellungen, z. B. hell/dunkel. */
    #[Route('/preferences', name: 'api_me_preferences', methods: ['PUT'])]
    public function preferences(#[MapRequestPayload] PreferencesInput $input, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->currentUser()->setTheme($input->theme);
        $em->flush();

        return $this->serialized($user, ['user:read']);
    }

    #[Route('/password', name: 'api_me_password', methods: ['PUT'])]
    public function changePassword(
        #[MapRequestPayload] PasswordInput $input,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $user = $this->currentUser();

        if (!$hasher->isPasswordValid($user, $input->currentPassword)) {
            return $this->rejected('Das aktuelle Passwort stimmt nicht.');
        }

        $user->setPassword($hasher->hashPassword($user, $input->newPassword));
        $em->flush();

        return $this->noContent();
    }
}
