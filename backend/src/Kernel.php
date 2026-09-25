<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Arbeitszeiten ("ab 07:00") gelten in der Zeitzone der Werkstatt, nicht
     * in der des Servers – Container laufen sonst in UTC.
     */
    public function boot(): void
    {
        date_default_timezone_set($_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'Europe/Vienna');

        parent::boot();
    }
}
