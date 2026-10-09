<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Basis fuer API-Tests: frische SQLite-Datenbank je Test, eine
 * steuerbare Uhr (MockClock) und Anmeldung per JWT.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected MockClock $clock;

    protected User $chef;
    protected User $worker;
    protected User $otherWorker;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->clock = new MockClock('2026-09-28 08:00:00', 'Europe/Vienna');
        $container->set('clock', $this->clock);

        $this->em = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->chef = $this->user('p.hofer', 'Peter', 'Hofer', UserRole::Chef);
        $this->worker = $this->user('a.huber', 'Anton', 'Huber', UserRole::Worker);
        $this->otherWorker = $this->user('b.steiner', 'Bernd', 'Steiner', UserRole::Worker);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    protected function api(string $method, string $path, User $as, ?array $body = null, int $expected = 200): ?array
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($as);

        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null !== $body ? json_encode($body, \JSON_THROW_ON_ERROR) : null);

        $response = $this->client->getResponse();
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        $content = (string) $response->getContent();

        return '' === $content ? null : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** Uhr vorstellen, z. B. "2026-09-28 12:00". */
    protected function at(string $moment): void
    {
        $this->clock->modify($moment);
    }

    /** Frisch aus der Datenbank laden – prueft, dass wirklich gespeichert wurde. */
    protected function reload(int $id): Job
    {
        $this->em->clear();

        return $this->em->find(Job::class, $id) ?? self::fail('Arbeit nicht gefunden');
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function createJob(array $extra = []): array
    {
        // Bequemlichkeit fuer Tests: 'minutes' = Laenge des Kalenderblocks ab startsAt.
        if (isset($extra['minutes'], $extra['startsAt'])) {
            $extra['endsAt'] = (new \DateTimeImmutable($extra['startsAt']))->modify(sprintf('+%d minutes', $extra['minutes']))->format(\DATE_ATOM);
        }
        unset($extra['minutes']);

        return $this->api('POST', '/api/jobs', $this->chef, $extra + [
            'title' => 'Bremsen hinten',
            'assigneeId' => $this->worker->getId(),
        ], 201);
    }

    private function user(string $username, string $first, string $last, UserRole $role): User
    {
        $user = (new User())->setUsername($username)->setFirstName($first)->setLastName($last)->setRole($role)->setPassword('x');
        $this->em->persist($user);

        return $user;
    }
}
