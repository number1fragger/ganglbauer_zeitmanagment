<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** F7 – ab wann neue Arbeit gebraucht wird. */
final class WorkRequestInput
{
    public function __construct(
        #[Assert\NotNull(message: 'Bitte angeben, ab wann du wieder Arbeit brauchst.')]
        public readonly ?\DateTimeImmutable $neededAt = null,
    ) {
    }
}
