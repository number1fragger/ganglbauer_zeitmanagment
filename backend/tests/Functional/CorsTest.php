<?php

namespace App\Tests\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Frontend auf einer anderen Domain: Die in CORS_ALLOW_ORIGIN eingetragene
 * Domain (in .env.test https://zeit.example.at) darf die API aufrufen,
 * jede andere nicht.
 */
final class CorsTest extends ApiTestCase
{
    private const ALLOWED = 'https://zeit.example.at';

    public function testPreflightIsAnsweredBeforeLogin(): void
    {
        $this->client->request('OPTIONS', '/api/me', server: [
            'HTTP_ORIGIN' => self::ALLOWED,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::ALLOWED);
        self::assertStringContainsString('Authorization', (string) $this->client->getResponse()->headers->get('Access-Control-Allow-Headers'));
    }

    public function testResponsesCarryTheOriginHeader(): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->worker);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_ORIGIN' => self::ALLOWED,
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::ALLOWED);
    }

    public function testErrorsCarryTheOriginHeaderSoTheAppCanReadThem(): void
    {
        $this->client->request('GET', '/api/me', server: ['HTTP_ORIGIN' => self::ALLOWED]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::ALLOWED);
    }

    public function testForeignOriginIsNotAllowed(): void
    {
        $this->client->request('OPTIONS', '/api/me', server: [
            'HTTP_ORIGIN' => 'https://boese.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertNotSame(204, $this->client->getResponse()->getStatusCode());
        self::assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }
}
