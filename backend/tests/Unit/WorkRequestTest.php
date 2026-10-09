<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Entity\WorkRequest;
use PHPUnit\Framework\TestCase;

/** F8 – Anfrage fruehestens fuer den naechsten Tag. */
final class WorkRequestTest extends TestCase
{
    private const NOW = '2026-09-28 10:00:00';

    public function testSameDayRequestIsRejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->request('2026-09-28 13:00:00');
    }

    public function testTomorrowMorningIsAllowedEvenIfLessThan24HoursAway(): void
    {
        $request = $this->request('2026-09-29 07:00:00');

        self::assertTrue($request->isOpen());
    }

    public function testWithdrawnRequestIsNoLongerOpen(): void
    {
        $request = $this->request('2026-09-30 07:00:00')->withdraw();

        self::assertFalse($request->isOpen());
    }

    private function request(string $neededAt): WorkRequest
    {
        return new WorkRequest(new User(), new \DateTimeImmutable($neededAt), new \DateTimeImmutable(self::NOW));
    }
}
