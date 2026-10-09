<?php

namespace App\Dto;

use App\Service\JobScheduler;
use Symfony\Component\Validator\Constraints as Assert;

/** F5 – um wie viele Minuten die geplante Zeit erhoeht wird. */
final class ExtendInput
{
    public function __construct(
        #[Assert\Range(min: 1, max: JobScheduler::MAX_EXTENSION_MINUTES, notInRangeMessage: 'Bitte zwischen 1 Minute und 8 Stunden angeben.')]
        public readonly int $minutes = 60,
    ) {
    }
}
