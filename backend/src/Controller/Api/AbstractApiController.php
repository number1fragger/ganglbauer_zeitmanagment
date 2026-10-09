<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Util\LocalTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class AbstractApiController extends AbstractController
{
    protected function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param string[] $groups
     */
    protected function serialized(mixed $data, array $groups, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json($data, $status, [], ['groups' => $groups]);
    }

    protected function noContent(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Meldung aus der Fachlogik (z. B. Vorlaufzeit) als 422 an den Client. */
    protected function rejected(string $message): JsonResponse
    {
        return $this->json(['title' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    protected function parseDate(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return LocalTime::of(new \DateTimeImmutable($value));
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('"%s" ist kein gueltiges Datum.', $field));
        }
    }
}
