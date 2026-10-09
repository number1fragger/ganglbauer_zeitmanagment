<?php

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['username'], message: 'Dieser Benutzername ist schon vergeben.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'user:ref'])]
    private ?int $id = null;

    /** Anmeldename, z. B. "p.hofer". */
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank(message: 'Bitte einen Benutzernamen angeben.')]
    #[Assert\Regex('/^[a-z0-9._-]{3,50}$/', message: 'Nur Kleinbuchstaben, Ziffern, Punkt, Binde- und Unterstrich (3–50 Zeichen).')]
    #[Groups(['user:read'])]
    private string $username = '';

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Bitte den Vornamen angeben.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read'])]
    private string $firstName = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Bitte den Nachnamen angeben.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read'])]
    private string $lastName = '';

    #[ORM\Column(type: 'string', length: 20, enumType: UserRole::class)]
    #[Groups(['user:read'])]
    private UserRole $role = UserRole::Worker;

    /** Wochenarbeitszeit in Stunden – Basis fuer Auslastung und Prognose. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 60, notInRangeMessage: 'Die Wochenstunden muessen zwischen 1 und 60 liegen.')]
    #[Groups(['user:read'])]
    private float $weeklyHours = 38.5;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = mb_strtolower(trim($username));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): static
    {
        $this->password = $hashedPassword;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);

        return $this;
    }

    #[Groups(['user:read', 'user:ref'])]
    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /** "Anton Huber" -> "AH" */
    #[Groups(['user:read', 'user:ref'])]
    public function getInitials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1).mb_substr($this->lastName, 0, 1));
    }

    /** "Bernd Steiner" -> "B. Steiner" */
    #[Groups(['user:read', 'user:ref'])]
    public function getShortName(): string
    {
        return trim(mb_substr($this->firstName, 0, 1).'. '.$this->lastName);
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getWeeklyHours(): float
    {
        return $this->weeklyHours;
    }

    public function setWeeklyHours(float $weeklyHours): static
    {
        $this->weeklyHours = $weeklyHours;

        return $this;
    }

    /** Taegliche Arbeitszeit in Minuten (Mo–Fr). */
    public function getDailyMinutes(): int
    {
        return (int) round($this->weeklyHours * 60 / 5);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
