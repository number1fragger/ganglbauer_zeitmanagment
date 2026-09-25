<?php

namespace App\Controller\Api;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

abstract class AbstractApiController extends AbstractController
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            throw new BadRequestHttpException('Ungueltiges JSON im Request-Body.');
        }

        return $data;
    }

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
    protected function item(mixed $data, array $groups, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json($data, $status, [], ['groups' => $groups]);
    }

    protected function violations(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return $this->json([
            'title' => 'Validierung fehlgeschlagen',
            'errors' => $errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    protected function parseDate(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            // Der Browser schickt UTC ("...Z"). Doctrine speichert ohne Zeitzone,
            // deshalb erst in die Zeitzone der Werkstatt umrechnen.
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            throw new BadRequestHttpException(sprintf('"%s" ist kein gueltiges Datum.', $field));
        }
    }
}
