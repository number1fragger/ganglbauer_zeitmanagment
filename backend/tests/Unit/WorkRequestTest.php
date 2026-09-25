<?php

namespace App\Tests\Unit;

use App\Entity\WorkRequest;
use App\Enum\WorkRequestStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class WorkRequestTest extends TestCase
{
    public function testRequestWithoutLeadTimeIsRejected(): void
    {
        $request = new WorkRequest();
        $request->setNeededAt(new \DateTimeImmutable('+2 hours'));

        self::assertGreaterThan(0, \count($this->validate($request)));
    }

    public function testRequestWithOneDayLeadTimeIsAccepted(): void
    {
        $request = new WorkRequest();
        $request->setNeededAt(new \DateTimeImmutable('+2 days'));

        self::assertCount(0, $this->validate($request));
        self::assertSame(WorkRequestStatus::Open, $request->getStatus());
    }

    private function validate(WorkRequest $request): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return $validator->validate($request);
    }
}
