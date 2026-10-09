<?php

namespace App\Enum;

enum JobStatus: string
{
    case Open = 'offen';
    case InProgress = 'in_arbeit';
    case Done = 'erledigt';
}
