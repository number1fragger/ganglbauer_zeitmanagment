<?php

namespace App\Entity;

use App\Enum\JobStatus;
use App\Enum\Priority;
use App\Repository\JobRepository;
use App\Util\LocalTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Eine Arbeit in der Werkstatt (F1–F6).
 *
 * Beginn und Ende sind immer gesetzt, damit die Arbeit im Kalender
 * erscheint. Ohne explizites Ende gilt Beginn + geplante Zeit.
 */
#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Index(name: 'idx_job_schedule', columns: ['starts_at', 'ends_at'])]
#[ORM\Index(name: 'idx_job_assignee_status', columns: ['assignee_id', 'status'])]
class Job
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['job:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Groups(['job:read'])]
    private string $title = '';

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['job:read'])]
    private ?string $customer = null;

    #[ORM\Column(type: 'string', length: 20, enumType: Priority::class)]
    #[Groups(['job:read'])]
    private Priority $priority = Priority::Medium;

    #[ORM\Column(type: 'string', length: 20, enumType: JobStatus::class)]
    #[Groups(['job:read'])]
    private JobStatus $status = JobStatus::Open;

    /** F1 – geplante Arbeitszeit in Minuten, kann per F5 erhoeht werden. */
    #[ORM\Column]
    #[Groups(['job:read'])]
    private int $plannedMinutes = 60;

    /** Urspruenglich geplante Zeit – Soll-Wert fuer den Soll/Ist-Vergleich (A1). */
    #[ORM\Column]
    #[Groups(['job:read'])]
    private int $originalPlannedMinutes = 60;

    /** F3 – geplanter Arbeitsbeginn. */
    #[ORM\Column]
    #[Groups(['job:read'])]
    private \DateTimeImmutable $startsAt;

    /** F3 – geplantes Arbeitsende. */
    #[ORM\Column]
    #[Groups(['job:read'])]
    private \DateTimeImmutable $endsAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['job:read'])]
    private ?User $assignee = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['job:read'])]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TimeEntry> */
    #[ORM\OneToMany(targetEntity: TimeEntry::class, mappedBy: 'job', orphanRemoval: true)]
    private Collection $timeEntries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->startsAt = $this->createdAt;
        $this->endsAt = $this->createdAt->modify('+60 minutes');
        $this->timeEntries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getCustomer(): ?string
    {
        return $this->customer;
    }

    public function setCustomer(?string $customer): static
    {
        $customer = null !== $customer ? trim($customer) : null;
        $this->customer = '' === $customer ? null : $customer;

        return $this;
    }

    public function getPriority(): Priority
    {
        return $this->priority;
    }

    public function setPriority(Priority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function markInProgress(): static
    {
        if (JobStatus::Open === $this->status) {
            $this->status = JobStatus::InProgress;
        }

        return $this;
    }

    public function isDone(): bool
    {
        return JobStatus::Done === $this->status;
    }

    /**
     * F1/F3 – Termin und geplante Zeit festlegen. Ohne Ende endet die
     * Arbeit nach der geplanten Zeit.
     */
    public function schedule(\DateTimeImmutable $startsAt, int $plannedMinutes, ?\DateTimeImmutable $endsAt = null): static
    {
        if ($plannedMinutes <= 0) {
            throw new \InvalidArgumentException('Die geplante Zeit muss groesser als null sein.');
        }

        $startsAt = LocalTime::of($startsAt);
        $endsAt = null !== $endsAt ? LocalTime::of($endsAt) : $startsAt->modify(sprintf('+%d minutes', $plannedMinutes));

        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('Das Arbeitsende muss nach dem Arbeitsbeginn liegen.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->plannedMinutes = $plannedMinutes;

        // Solange die Arbeit neu ist, ist der Plan auch der Soll-Wert.
        if (null === $this->id) {
            $this->originalPlannedMinutes = $plannedMinutes;
        }

        return $this;
    }

    /** F5 – die geplante Zeit nachtraeglich erhoehen, das Ende rueckt mit. */
    public function extendBy(int $minutes): static
    {
        $this->plannedMinutes += $minutes;
        $this->endsAt = $this->endsAt->modify(sprintf('+%d minutes', $minutes));

        return $this;
    }

    /** Verschiebt den Termin, ohne die geplante Zeit zu aendern. */
    public function shiftBy(int $minutes): static
    {
        $this->startsAt = $this->startsAt->modify(sprintf('+%d minutes', $minutes));
        $this->endsAt = $this->endsAt->modify(sprintf('+%d minutes', $minutes));

        return $this;
    }

    public function getPlannedMinutes(): int
    {
        return $this->plannedMinutes;
    }

    public function getOriginalPlannedMinutes(): int
    {
        return $this->originalPlannedMinutes;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function isAssignedTo(User $user): bool
    {
        return null !== $this->assignee && $this->assignee->getId() === $user->getId();
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** F4 – Arbeit als erledigt abhaken. */
    public function complete(?\DateTimeImmutable $at = null): static
    {
        $this->status = JobStatus::Done;
        $this->completedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function reopen(): static
    {
        $this->status = $this->timeEntries->isEmpty() ? JobStatus::Open : JobStatus::InProgress;
        $this->completedAt = null;

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

    public function addTimeEntry(TimeEntry $entry): static
    {
        if (!$this->timeEntries->contains($entry)) {
            $this->timeEntries->add($entry);
            $entry->setJob($this);
        }

        return $this;
    }

    /** Tatsaechlich aufgewendete Zeit in Minuten (Ist-Wert fuer A1). */
    #[Groups(['job:read'])]
    public function getActualMinutes(): int
    {
        $total = 0;
        foreach ($this->timeEntries as $entry) {
            $total += $entry->getDurationMinutes();
        }

        return $total;
    }

    #[Groups(['job:read'])]
    public function getRemainingMinutes(): int
    {
        if ($this->isDone()) {
            return 0;
        }

        return max(0, $this->plannedMinutes - $this->getActualMinutes());
    }

    /** F6 – die festgelegte Arbeitszeit ist ueberschritten. */
    #[Groups(['job:read'])]
    public function isOverrun(): bool
    {
        return $this->getOverrunMinutes() > 0;
    }

    #[Groups(['job:read'])]
    public function getOverrunMinutes(): int
    {
        return max(0, $this->getActualMinutes() - $this->plannedMinutes);
    }

    #[Groups(['job:read'])]
    public function isRunning(): bool
    {
        foreach ($this->timeEntries as $entry) {
            if ($entry->isRunning()) {
                return true;
            }
        }

        return false;
    }
}
