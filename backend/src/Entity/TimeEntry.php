<?php

namespace App\Entity;

use App\Repository\TimeEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Ein Zeitabschnitt, den ein Arbeiter an einer Arbeit verbracht hat.
 * Liefert den Ist-Wert fuer den Soll/Ist-Vergleich (A1).
 */
#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Index(name: 'idx_entry_user_start', columns: ['user_id', 'started_at'])]
class TimeEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['entry:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'timeEntries')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['entry:read'])]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'timeEntries')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['entry:read'])]
    private ?Job $job = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['entry:read', 'entry:write'])]
    private ?string $note = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['entry:read', 'entry:write'])]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['entry:read', 'entry:write'])]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column]
    #[Groups(['entry:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateInterval(ExecutionContextInterface $context): void
    {
        if (null !== $this->endedAt && $this->endedAt <= $this->startedAt) {
            $context->buildViolation('Das Ende muss nach dem Beginn liegen.')
                ->atPath('endedAt')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getJob(): ?Job
    {
        return $this->job;
    }

    public function setJob(?Job $job): static
    {
        $this->job = $job;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[Groups(['entry:read'])]
    public function isRunning(): bool
    {
        return null === $this->endedAt;
    }

    /** Dauer in Minuten – bei laufenden Eintraegen bis jetzt gerechnet. */
    #[Groups(['entry:read'])]
    public function getDurationMinutes(): int
    {
        $end = $this->endedAt ?? new \DateTimeImmutable();

        return (int) max(0, floor(($end->getTimestamp() - $this->startedAt->getTimestamp()) / 60));
    }
}
