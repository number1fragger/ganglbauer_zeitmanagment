<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erlaubt Aufrufe der API von einer anderen Domain (CORS), z. B. wenn das
 * Frontend auf einem eigenen Webspace liegt. Welche Domains duerfen, steht
 * kommagetrennt in CORS_ALLOW_ORIGIN. Leer (Standard) = keine fremden Domains,
 * dann aendert sich nichts.
 *
 * Das Login-Token kommt im Authorization-Header, nicht per Cookie – deshalb
 * braucht es keine "Credentials".
 */
final class CorsListener
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, Accept';
    private const MAX_AGE = '3600';

    /** @var list<string> */
    private readonly array $allowedOrigins;

    public function __construct(
        #[Autowire('%env(CORS_ALLOW_ORIGIN)%')]
        string $allowedOrigins,
    ) {
        $this->allowedOrigins = array_values(array_filter(array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', $allowedOrigins),
        )));
    }

    /**
     * Preflight (OPTIONS) sofort beantworten – noch vor der Firewall, die
     * sonst ohne Token mit 401 abbrechen wuerde.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || !$request->isMethod('OPTIONS')
            || !$request->headers->has('Access-Control-Request-Method')
            || !$this->isAllowed($request)
        ) {
            return;
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Max-Age', self::MAX_AGE);
        $event->setResponse($response);
    }

    /** Gilt fuer alle Antworten, auch 401 und Fehlermeldungen. */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || [] === $this->allowedOrigins || !$this->isApi($request)) {
            return;
        }

        $response = $event->getResponse();
        $response->setVary('Origin', false);

        if ($this->isAllowed($request)) {
            $response->headers->set('Access-Control-Allow-Origin', (string) $request->headers->get('Origin'));
        }
    }

    private function isAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return null !== $origin
            && $this->isApi($request)
            && (\in_array('*', $this->allowedOrigins, true) || \in_array(rtrim($origin, '/'), $this->allowedOrigins, true));
    }

    private function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api');
    }
}
