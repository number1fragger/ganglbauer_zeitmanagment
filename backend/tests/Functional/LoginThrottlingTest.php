<?php

namespace App\Tests\Functional;

/**
 * Schutz gegen Passwort-Raten: nach 5 Fehlversuchen ist das Konto von dieser
 * IP-Adresse aus fuer 15 Minuten gesperrt – auch mit dem richtigen Passwort.
 */
final class LoginThrottlingTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Die Fehlversuche liegen im Cache unter var/ – vom letzten Lauf nichts mitnehmen.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testLoginIsBlockedAfterFiveFailedAttempts(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertStringContainsString('Invalid credentials', $this->login('a.huber', 'falsch'));
        }

        self::assertStringContainsString('Too many failed login attempts', $this->login('a.huber', 'falsch'));
        self::assertResponseStatusCodeSame(401);
    }

    private function login(string $username, string $password): string
    {
        $this->client->request('POST', '/api/login', server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.7',
        ], content: json_encode(['username' => $username, 'password' => $password], \JSON_THROW_ON_ERROR));

        return (string) $this->client->getResponse()->getContent();
    }
}
