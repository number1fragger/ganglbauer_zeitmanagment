<?php

namespace App\Dto;

use App\Enum\UserRole;
use Symfony\Component\Validator\Constraints as Assert;

/** Benutzer anlegen bzw. bearbeiten (nur Chef). */
final class UserInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Bitte einen Benutzernamen angeben.', groups: ['create'])]
        public readonly ?string $username = null,

        #[Assert\NotBlank(message: 'Bitte den Vornamen angeben.')]
        public readonly string $firstName = '',

        #[Assert\NotBlank(message: 'Bitte den Nachnamen angeben.')]
        public readonly string $lastName = '',

        public readonly UserRole $role = UserRole::Worker,

        #[Assert\Range(min: 1, max: 60, notInRangeMessage: 'Die Wochenstunden muessen zwischen 1 und 60 liegen.')]
        public readonly float $weeklyHours = 38.5,

        public readonly bool $active = true,

        /** Beim Anlegen Pflicht, beim Bearbeiten nur zum Zuruecksetzen. */
        #[Assert\NotBlank(message: 'Bitte ein Passwort vergeben.', groups: ['create'])]
        #[Assert\Length(min: 8, minMessage: 'Das Passwort braucht mindestens 8 Zeichen.')]
        public readonly ?string $password = null,
    ) {
    }
}
