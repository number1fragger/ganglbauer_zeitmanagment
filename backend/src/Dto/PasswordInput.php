<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Bitte das aktuelle Passwort angeben.')]
        public readonly string $currentPassword = '',

        #[Assert\Length(min: 8, minMessage: 'Das neue Passwort braucht mindestens 8 Zeichen.')]
        public readonly string $newPassword = '',
    ) {
    }
}
