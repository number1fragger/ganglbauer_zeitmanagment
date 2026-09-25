<?php

namespace App\Enum;

enum WorkRequestStatus: string
{
    case Open = 'offen';
    case Fulfilled = 'zugeteilt';
    case Cancelled = 'zurueckgezogen';
}
