<?php

namespace App\Entity;

use App\Repository\TimeEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Zeitabschnitt, den ein Arbeiter an einer Arbeit verbracht hat.
 * Liefert den Ist-Wert fuer den Soll/Ist-Vergleich (A1).
 */
#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Index(name: 'idx_entry_user_end', columns: ['user_id', 'ended_at'])]
class TimeEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(inversedBy: 'timeEntries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Job $job;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(User $user, Job $job, ?\DateTimeImmutable $startedAt = null)
    {
        $this->user = $user;
        $this->startedAt = $startedAt ?? new \DateTimeImmutable();
        $job->addTimeEntry($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function setJob(Job $job): static
    {
        $this->job = $job;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /** Beendet den Eintrag – mindestens eine Minute nach dem Start. */
    public function stop(?\DateTimeImmutable $at = null): static
    {
        $end = $at ?? new \DateTimeImmutable();
        $minimum = $this->startedAt->modify('+1 minute');

        $this->endedAt = $end < $minimum ? $minimum : $end;

        return $this;
    }

    public function isRunning(): bool
    {
        return null === $this->endedAt;
    }

    /** Dauer in Minuten – laufende Eintraege werden bis jetzt gerechnet. */
    public function getDurationMinutes(): int
    {
        $end = $this->endedAt ?? new \DateTimeImmutable();

        return (int) max(0, floor(($end->getTimestamp() - $this->startedAt->getTimestamp()) / 60));
    }
}
