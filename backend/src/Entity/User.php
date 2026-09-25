<?php

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Diese E-Mail-Adresse wird bereits verwendet.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'entry:read', 'job:read', 'request:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:write'])]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['user:read', 'user:write', 'entry:read', 'job:read', 'request:read'])]
    private string $firstName = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['user:read', 'user:write', 'entry:read', 'job:read', 'request:read'])]
    private string $lastName = '';

    /** Wochenarbeitszeit in Stunden (Basis fuer Soll-/Ist-Vergleich). */
    #[ORM\Column(type: 'float')]
    #[Assert\Positive]
    #[Groups(['user:read', 'user:write'])]
    private float $weeklyHours = 38.5;

    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private bool $active = true;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TimeEntry> */
    #[ORM\OneToMany(targetEntity: TimeEntry::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $timeEntries;

    /** @var Collection<int, Job> */
    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'assignee')]
    private Collection $jobs;

    /** @var Collection<int, WorkRequest> */
    #[ORM\OneToMany(targetEntity: WorkRequest::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $workRequests;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->timeEntries = new ArrayCollection();
        $this->jobs = new ArrayCollection();
        $this->workRequests = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /** Die hoechste Rolle – bestimmt, welche Ansichten sichtbar sind. */
    #[Groups(['user:read'])]
    public function getRole(): string
    {
        return UserRole::highestOf($this->getRoles())->value;
    }

    #[Groups(['user:read'])]
    public function getRoleLabel(): string
    {
        return UserRole::highestOf($this->getRoles())->label();
    }

    public function setRole(UserRole $role): static
    {
        $this->roles = $role->storedRoles();

        return $this;
    }

    public function hasRole(UserRole $role): bool
    {
        return \in_array($role->value, $this->getRoles(), true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

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
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    #[Groups(['user:read', 'entry:read', 'job:read', 'request:read'])]
    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /** Kuerzel fuer Kalenderkarten, z. B. "KL" fuer Kevin Lichtl. */
    #[Groups(['user:read', 'job:read'])]
    public function getInitials(): string
    {
        $initials = mb_substr($this->firstName, 0, 1).mb_substr($this->lastName, 0, 1);

        return mb_strtoupper('' !== $initials ? $initials : mb_substr($this->email, 0, 2));
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

    /** @return Collection<int, TimeEntry> */
    public function getTimeEntries(): Collection
    {
        return $this->timeEntries;
    }

    /** @return Collection<int, Job> */
    public function getJobs(): Collection
    {
        return $this->jobs;
    }

    /** @return Collection<int, WorkRequest> */
    public function getWorkRequests(): Collection
    {
        return $this->workRequests;
    }

    /** Taegliche Arbeitszeit in Minuten – Basis fuer die Auslastungsprognose. */
    #[Groups(['user:read'])]
    public function getDailyMinutes(): int
    {
        return (int) round($this->weeklyHours * 60 / 5);
    }
}
