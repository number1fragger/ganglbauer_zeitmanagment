<?php

namespace App\Enum;

enum JobStatus: string
{
    case Open = 'offen';
    case InProgress = 'in_arbeit';
    case Done = 'erledigt';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::InProgress => 'In Arbeit',
            self::Done => 'Erledigt',
        };
    }
}
