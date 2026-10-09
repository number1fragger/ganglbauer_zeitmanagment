<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Bestaetigte Umplanung: welche Folgearbeiten verschoben werden sollen. */
final class RescheduleInput
{
    /**
     * @param int[] $jobIds
     */
    public function __construct(
        #[Assert\All([new Assert\Type('integer')])]
        public readonly array $jobIds = [],
    ) {
    }
}
