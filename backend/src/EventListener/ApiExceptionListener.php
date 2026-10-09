<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Liefert fuer /api/* immer JSON im Format {title, errors?} statt
 * HTML-Fehlerseiten – auch fuer Validierungsfehler aus MapRequestPayload.
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiExceptionListener
{
    private const MESSAGES = [
        Response::HTTP_FORBIDDEN => 'Dafuer fehlt dir die Berechtigung.',
        Response::HTTP_NOT_FOUND => 'Nicht gefunden.',
        Response::HTTP_METHOD_NOT_ALLOWED => 'Diese Aktion ist hier nicht erlaubt.',
    ];

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $violations = $this->violationsOf($exception);

        if (null !== $violations) {
            $event->setResponse(new JsonResponse([
                'title' => 'Bitte die Eingaben pruefen.',
                'errors' => array_map(static fn ($violation): array => [
                    'field' => $violation->getPropertyPath(),
                    'message' => str_starts_with((string) $violation->getMessage(), 'This value should be of type')
                        ? 'Ungueltiger Wert.'
                        : (string) $violation->getMessage(),
                ], iterator_to_array($violations)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $title = match (true) {
            isset(self::MESSAGES[$status]) => self::MESSAGES[$status],
            $status >= 500 && !$this->debug => 'Interner Serverfehler',
            default => $exception->getMessage(),
        };

        $payload = ['title' => $title];
        if ($this->debug) {
            $payload['exception'] = $exception::class.': '.$exception->getMessage();
            $payload['file'] = $exception->getFile().':'.$exception->getLine();
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }

    private function violationsOf(\Throwable $exception): ?ConstraintViolationListInterface
    {
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof ValidationFailedException) {
                return $e->getViolations();
            }
        }

        return null;
    }
}
