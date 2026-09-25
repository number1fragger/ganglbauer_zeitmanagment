<?php

namespace App\Enum;

/**
 * Die drei Rollen der Werkstatt. Die Rechte bauen aufeinander auf
 * (siehe role_hierarchy in security.yaml):
 *
 *   Chef (ROLE_ADMIN)  >  Vorarbeiter (ROLE_FOREMAN)  >  Arbeiter (ROLE_USER)
 */
enum UserRole: string
{
    case Admin = 'ROLE_ADMIN';
    case Foreman = 'ROLE_FOREMAN';
    case Worker = 'ROLE_USER';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Chef',
            self::Foreman => 'Vorarbeiter',
            self::Worker => 'Arbeiter',
        };
    }

    /**
     * Die hoechste Rolle aus einer Rollenliste – ein Benutzer mit
     * ROLE_ADMIN und ROLE_USER ist also "Chef".
     *
     * @param string[] $roles
     */
    public static function highestOf(array $roles): self
    {
        foreach ([self::Admin, self::Foreman] as $role) {
            if (\in_array($role->value, $roles, true)) {
                return $role;
            }
        }

        return self::Worker;
    }

    /**
     * Rollen, die in der Datenbank gespeichert werden. ROLE_USER bekommt
     * ohnehin jeder (User::getRoles), daher bleibt die Liste beim Arbeiter leer.
     *
     * @return list<string>
     */
    public function storedRoles(): array
    {
        return self::Worker === $this ? [] : [$this->value];
    }
}
