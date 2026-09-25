<?php

namespace App\Tests\Unit;

use App\Entity\WorkRequest;
use App\Enum\WorkRequestStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

class WorkRequestTest extends TestCase
{
    public function testRequestForTodayIsRejected(): void
    {
        $request = new WorkRequest();
        $request->setNeededAt((new \DateTimeImmutable())->setTime(23, 0));

        self::assertGreaterThan(0, \count($this->validate($request)));
    }

    public function testTomorrowMorningIsAlwaysAccepted(): void
    {
        // "morgen frueh" muss auch dann gehen, wenn es schon 15:00 ist –
        // mit einer starren 24-Stunden-Regel waere das abgelehnt worden.
        $request = new WorkRequest();
        $request->setNeededAt((new \DateTimeImmutable('+1 day'))->setTime(7, 0));

        self::assertCount(0, $this->validate($request));
        self::assertSame(WorkRequestStatus::Open, $request->getStatus());
    }

    public function testDayAfterTomorrowIsAccepted(): void
    {
        $request = new WorkRequest();
        $request->setNeededAt((new \DateTimeImmutable('+2 days'))->setTime(13, 0));

        self::assertCount(0, $this->validate($request));
    }

    private function validate(WorkRequest $request): ConstraintViolationListInterface
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return $validator->validate($request);
    }
}
