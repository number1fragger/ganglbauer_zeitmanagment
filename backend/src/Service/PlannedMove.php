<?php

namespace App\Service;

use App\Entity\Job;

/** Geplante neue Zeit einer Arbeit – noch nicht auf die Arbeit angewendet. */
final class PlannedMove
{
    public function __construct(
        public readonly Job $job,
        public readonly \DateTimeImmutable $startsAt,
        public readonly \DateTimeImmutable $endsAt,
    ) {
    }

    public function apply(): void
    {
        $this->job->schedule($this->startsAt, $this->endsAt);
    }

    /** Kopie der Arbeit mit der geplanten Zeit – fuer Vorschauen, ohne die verwaltete Arbeit zu aendern. */
    public function previewJob(): Job
    {
        $copy = clone $this->job;
        $copy->schedule($this->startsAt, $this->endsAt);

        return $copy;
    }
}
