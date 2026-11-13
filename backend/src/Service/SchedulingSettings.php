<?php

namespace App\Service;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Einstellungen zur Einsatzplanung. Ohne gespeicherte Einstellung duerfen
 * sich Arbeiten eines Mitarbeiters ueberschneiden (bisheriges Verhalten).
 */
class SchedulingSettings
{
    private const PARALLEL = 'parallel_scheduling';

    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function allowsParallel(): bool
    {
        $setting = $this->settings->find(self::PARALLEL);

        return null === $setting || false !== $setting->getValue();
    }

    public function setAllowsParallel(bool $allowed): void
    {
        $setting = $this->settings->find(self::PARALLEL);
        if (null === $setting) {
            $this->em->persist(new AppSetting(self::PARALLEL, $allowed));
        } else {
            $setting->setValue($allowed);
        }

        $this->em->flush();
    }
}
