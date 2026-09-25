<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Liefert fuer /api/* immer sauberes JSON statt HTML-Fehlerseiten.
 */
#[AsEventListener(event: 'kernel.exception', priority: 0)]
class ApiExceptionListener
{
    public function __construct(private readonly bool $debug = false)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        $payload = [
            'status' => $status,
            'title' => $status >= 500 && !$this->debug
                ? 'Interner Serverfehler'
                : $exception->getMessage(),
        ];

        if ($this->debug) {
            $payload['exception'] = $exception::class;
            $payload['file'] = $exception->getFile().':'.$exception->getLine();
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
