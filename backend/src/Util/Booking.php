<?php

namespace App\Util;

/**
 * Eine Buchung im Kalender fuer den ScheduleSolver: wer ist von wann bis
 * wann belegt? "fest" heisst: darf nie verschoben werden.
 */
final class Booking
{
    /**
     * @param list<int> $persons Ids der eingeteilten Personen (zustaendig + Lehrling)
     */
    public function __construct(
        public readonly int $key,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly array $persons,
        public readonly bool $fixed = false,
    ) {
    }

    public function withTimes(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return new self($this->key, $start, $end, $this->persons, $this->fixed);
    }

    public function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $this->start < $end && $this->end > $start;
    }

    public function sharesPersonWith(self $other): bool
    {
        return [] !== array_intersect($this->persons, $other->persons);
    }
}
