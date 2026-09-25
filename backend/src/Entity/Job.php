<?php

namespace App\Entity;

use App\Enum\JobStatus;
use App\Enum\Priority;
use App\Repository\JobRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Eine Arbeit in der Werkstatt (F1–F6).
 */
#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Index(name: 'idx_job_assignee_status', columns: ['assignee_id', 'status'])]
class Job
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['job:read', 'entry:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Bitte einen Titel fuer die Arbeit angeben.')]
    #[Assert\Length(max: 150)]
    #[Groups(['job:read', 'job:write', 'entry:read'])]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['job:read', 'job:write'])]
    private ?string $description = null;

    /** Kunde, fuer den die Arbeit erledigt wird. */
    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['job:read', 'job:write', 'entry:read'])]
    private ?string $customer = null;

    #[ORM\Column(type: 'string', length: 20, enumType: Priority::class)]
    #[Groups(['job:read', 'job:write'])]
    private Priority $priority = Priority::Normal;

    #[ORM\Column(type: 'string', length: 20, enumType: JobStatus::class)]
    #[Groups(['job:read'])]
    private JobStatus $status = JobStatus::Open;

    /** F1 – geplante Arbeitszeit in Minuten, kann per F5 erhoeht werden. */
    #[ORM\Column]
    #[Assert\Positive(message: 'Die geplante Zeit muss groesser als null sein.')]
    #[Groups(['job:read', 'job:write'])]
    private int $plannedMinutes = 60;

    /** Urspruenglich geplante Zeit – Basis fuer den Soll/Ist-Vergleich (A1). */
    #[ORM\Column]
    #[Groups(['job:read'])]
    private int $originalPlannedMinutes = 60;

    /** F3 – geplanter Arbeitsbeginn. */
    #[ORM\Column(nullable: true)]
    #[Groups(['job:read', 'job:write'])]
    private ?\DateTimeImmutable $startsAt = null;

    /** F3 – geplantes Arbeitsende. */
    #[ORM\Column(nullable: true)]
    #[Groups(['job:read', 'job:write'])]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\ManyToOne(inversedBy: 'jobs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['job:read'])]
    private ?User $assignee = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['job:read'])]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    #[Groups(['job:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TimeEntry> */
    #[ORM\OneToMany(targetEntity: TimeEntry::class, mappedBy: 'job', orphanRemoval: true)]
    private Collection $timeEntries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->timeEntries = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateSchedule(ExecutionContextInterface $context): void
    {
        if (null !== $this->startsAt && null !== $this->dueAt && $this->dueAt < $this->startsAt) {
            $context->buildViolation('Das Arbeitsende darf nicht vor dem Arbeitsbeginn liegen.')
                ->atPath('dueAt')
                ->addViolation();
        }
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
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCustomer(): ?string
    {
        return $this->customer;
    }

    public function setCustomer(?string $customer): static
    {
        $this->customer = $customer;

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

    public function setStatus(JobStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPlannedMinutes(): int
    {
        return $this->plannedMinutes;
    }

    public function setPlannedMinutes(int $plannedMinutes): static
    {
        $this->plannedMinutes = $plannedMinutes;

        if (0 === $this->originalPlannedMinutes || null === $this->id) {
            $this->originalPlannedMinutes = $plannedMinutes;
        }

        return $this;
    }

    /** F5 – die geplante Zeit nachtraeglich erhoehen. */
    public function extendBy(int $minutes): static
    {
        $this->plannedMinutes += max(0, $minutes);

        return $this;
    }

    public function getOriginalPlannedMinutes(): int
    {
        return $this->originalPlannedMinutes;
    }

    #[Groups(['job:read'])]
    public function getExtendedMinutes(): int
    {
        return $this->plannedMinutes - $this->originalPlannedMinutes;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        $this->dueAt = $dueAt;

        return $this;
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
        $this->status = JobStatus::Open;
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
        if (JobStatus::Done === $this->status) {
            return 0;
        }

        return max(0, $this->plannedMinutes - $this->getActualMinutes());
    }

    /** F6 – die festgelegte Arbeitszeit ist ueberschritten. */
    #[Groups(['job:read'])]
    public function isOverrun(): bool
    {
        return $this->getActualMinutes() > $this->plannedMinutes;
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

    /** Fortschritt in Prozent, gedeckelt bei 100 fuer die Balkendarstellung. */
    #[Groups(['job:read'])]
    public function getProgressPercent(): int
    {
        if ($this->plannedMinutes <= 0) {
            return 0;
        }

        return (int) min(100, round($this->getActualMinutes() / $this->plannedMinutes * 100));
    }
}
