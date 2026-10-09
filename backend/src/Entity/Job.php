<?php

namespace App\Entity;

use App\Enum\JobStatus;
use App\Enum\Priority;
use App\Exception\InvalidInputException;
use App\Exception\WorkflowException;
use App\Repository\JobRepository;
use App\Util\LocalTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Eine Arbeit (Aufgabe) in der Werkstatt.
 *
 * Drei Zeitangaben werden strikt getrennt:
 *  - geplante Arbeitszeit (plannedMinutes, optional) – wie lange gearbeitet werden soll
 *  - Termin im Kalender (startsAt/endsAt, optional) – wann die Arbeit offen/eingeplant ist,
 *    darf mehrere Tage umfassen
 *  - Ist-Zeit (Summe der TimeEntry-Abschnitte) – wie lange tatsaechlich gearbeitet wurde
 *
 * Ohne Termin ist die Arbeit eine reine Aufgabe (To-do) und erscheint
 * nicht im Kalender.
 */
#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Index(name: 'idx_job_schedule', columns: ['starts_at', 'ends_at'])]
#[ORM\Index(name: 'idx_job_assignee_status', columns: ['assignee_id', 'status'])]
class Job
{
    public const MAX_PLANNED_MINUTES = 10080;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['job:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Groups(['job:read'])]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['job:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['job:read'])]
    private ?string $customer = null;

    #[ORM\Column(type: 'string', length: 20, enumType: Priority::class)]
    #[Groups(['job:read'])]
    private Priority $priority = Priority::Medium;

    #[ORM\Column(type: 'string', length: 20, enumType: JobStatus::class)]
    #[Groups(['job:read'])]
    private JobStatus $status = JobStatus::Open;

    /** Geplante Arbeitszeit in Minuten (optional), kann per F5 erhoeht werden. */
    #[ORM\Column(nullable: true)]
    #[Groups(['job:read'])]
    private ?int $plannedMinutes = null;

    /** Urspruenglich geplante Zeit – Soll-Wert fuer den Soll/Ist-Vergleich (A1). */
    #[ORM\Column(nullable: true)]
    #[Groups(['job:read'])]
    private ?int $originalPlannedMinutes = null;

    /** Termin: Beginn im Kalender (optional). */
    #[ORM\Column(nullable: true)]
    #[Groups(['job:read'])]
    private ?\DateTimeImmutable $startsAt = null;

    /** Termin: Ende im Kalender (optional, darf an einem spaeteren Tag liegen). */
    #[ORM\Column(nullable: true)]
    #[Groups(['job:read'])]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\ManyToOne]
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
    #[ORM\OrderBy(['startedAt' => 'ASC'])]
    #[Groups(['job:entries'])]
    private Collection $timeEntries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null !== $description ? trim($description) : null;
        $this->description = '' === $description ? null : $description;

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

    public function isDone(): bool
    {
        return JobStatus::Done === $this->status;
    }

    /** Wurde schon einmal daran gearbeitet? */
    #[Groups(['job:read'])]
    public function isStarted(): bool
    {
        return !$this->timeEntries->isEmpty();
    }

    // ---- Planung ----------------------------------------------------------

    /**
     * Termin festlegen. Ohne Ende endet der Termin nach der geplanten
     * Arbeitszeit; ohne beides ist kein Termin moeglich.
     */
    public function schedule(\DateTimeImmutable $startsAt, ?int $plannedMinutes = null, ?\DateTimeImmutable $endsAt = null): static
    {
        if (null !== $plannedMinutes) {
            $this->setPlannedMinutes($plannedMinutes);
        }

        $startsAt = LocalTime::of($startsAt);

        if (null === $endsAt) {
            $minutes = $plannedMinutes ?? $this->plannedMinutes
                ?? throw new InvalidInputException('Für einen Termin bitte das Ende oder die geplante Arbeitszeit angeben.');
            $endsAt = $startsAt->modify(sprintf('+%d minutes', $minutes));
        }

        $endsAt = LocalTime::of($endsAt);

        if ($endsAt <= $startsAt) {
            throw new InvalidInputException('Das Arbeitsende muss nach dem Arbeitsbeginn liegen.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;

        return $this;
    }

    /** Termin entfernen – die Arbeit bleibt als Aufgabe erhalten. */
    public function unschedule(): static
    {
        $this->startsAt = null;
        $this->endsAt = null;

        return $this;
    }

    #[Groups(['job:read'])]
    public function isScheduled(): bool
    {
        return null !== $this->startsAt && null !== $this->endsAt;
    }

    public function setPlannedMinutes(?int $minutes): static
    {
        if (null !== $minutes && ($minutes <= 0 || $minutes > self::MAX_PLANNED_MINUTES)) {
            throw new InvalidInputException('Die geplante Zeit muss zwischen einer Minute und einer Woche liegen.');
        }

        $this->plannedMinutes = $minutes;

        // Solange noch nicht gearbeitet wurde, ist der Plan auch der Soll-Wert.
        if (!$this->isStarted() && !$this->isDone()) {
            $this->originalPlannedMinutes = $minutes;
        }

        return $this;
    }

    /** F5 – die geplante Zeit nachtraeglich erhoehen, das Termin-Ende rueckt mit. */
    public function extendBy(int $minutes): static
    {
        $this->plannedMinutes = ($this->plannedMinutes ?? 0) + $minutes;

        // Gab es noch keinen Plan, wird der erste Plan zum Soll-Wert.
        if (null === $this->originalPlannedMinutes && !$this->isStarted()) {
            $this->originalPlannedMinutes = $this->plannedMinutes;
        }
        $this->endsAt = $this->endsAt?->modify(sprintf('+%d minutes', $minutes));

        return $this;
    }

    /** Verschiebt den Termin, ohne die Dauer zu aendern. */
    public function shiftBy(int $minutes): static
    {
        $this->startsAt = $this->startsAt?->modify(sprintf('%+d minutes', $minutes));
        $this->endsAt = $this->endsAt?->modify(sprintf('%+d minutes', $minutes));

        return $this;
    }

    /** Kuerzt den Termin auf das tatsaechliche Ende (nach frueherem Abschluss). */
    public function trimEndTo(\DateTimeImmutable $end): static
    {
        $end = LocalTime::of($end);

        if (null !== $this->startsAt && null !== $this->endsAt && $end > $this->startsAt && $end < $this->endsAt) {
            $this->endsAt = $end;
        }

        return $this;
    }

    public function getPlannedMinutes(): ?int
    {
        return $this->plannedMinutes;
    }

    public function getOriginalPlannedMinutes(): ?int
    {
        return $this->originalPlannedMinutes;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    /** Dauer des Termins im Kalender – nicht zu verwechseln mit der Arbeitszeit. */
    #[Groups(['job:read'])]
    public function getCalendarMinutes(): ?int
    {
        if (!$this->isScheduled()) {
            return null;
        }

        return intdiv($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp(), 60);
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ---- Arbeitsablauf ----------------------------------------------------
    // Die Zeitabschnitte verwaltet der TimeTracker; hier stehen nur die
    // Zustandswechsel und ihre Regeln.

    /** Es wird (wieder) daran gearbeitet. */
    public function markInProgress(): static
    {
        if ($this->isDone()) {
            throw new WorkflowException('Diese Arbeit ist bereits abgeschlossen.');
        }

        $this->status = JobStatus::InProgress;

        return $this;
    }

    /**
     * F4 – Arbeit abschliessen. Laufende Abschnitte muss der Aufrufer
     * vorher beenden (TimeTracker::complete), sonst wird abgelehnt.
     */
    public function complete(?\DateTimeImmutable $at = null): static
    {
        if ($this->isDone()) {
            throw new WorkflowException('Diese Arbeit ist bereits abgeschlossen.');
        }

        if ($this->isRunning()) {
            throw new WorkflowException('Bitte zuerst die laufende Zeiterfassung beenden.');
        }

        $this->status = JobStatus::Done;
        $this->completedAt = LocalTime::of($at ?? new \DateTimeImmutable());

        return $this;
    }

    /** Ausdrueckliche Wiedereroeffnung; die erfasste Zeit bleibt erhalten. */
    public function reopen(): static
    {
        if (!$this->isDone()) {
            throw new WorkflowException('Diese Arbeit ist nicht abgeschlossen.');
        }

        $this->status = $this->isStarted() ? JobStatus::InProgress : JobStatus::Open;
        $this->completedAt = null;

        return $this;
    }

    // ---- Ist-Zeit ---------------------------------------------------------

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

    /** Summe aller Arbeitsabschnitte in Sekunden. */
    #[Groups(['job:read'])]
    public function getActualSeconds(): int
    {
        $total = 0;
        foreach ($this->timeEntries as $entry) {
            $total += $entry->getDurationSeconds();
        }

        return $total;
    }

    /** Tatsaechlich gearbeitete Zeit in vollen Minuten (Ist-Wert fuer A1). */
    #[Groups(['job:read'])]
    public function getActualMinutes(): int
    {
        return intdiv($this->getActualSeconds(), 60);
    }

    /** Restzeit laut Plan; ohne geplante Zeit unbekannt (null). */
    #[Groups(['job:read'])]
    public function getRemainingMinutes(): ?int
    {
        if ($this->isDone()) {
            return 0;
        }

        if (null === $this->plannedMinutes) {
            return null;
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
        return null === $this->plannedMinutes ? 0 : max(0, $this->getActualMinutes() - $this->plannedMinutes);
    }

    public function getRunningEntry(): ?TimeEntry
    {
        foreach ($this->timeEntries as $entry) {
            if ($entry->isRunning()) {
                return $entry;
            }
        }

        return null;
    }

    #[Groups(['job:read'])]
    public function isRunning(): bool
    {
        return null !== $this->getRunningEntry();
    }

    /** Begonnen, aber gerade wird nicht daran gearbeitet. */
    #[Groups(['job:read'])]
    public function isPaused(): bool
    {
        return JobStatus::InProgress === $this->status && !$this->isRunning();
    }

    /** Beginn des laufenden Abschnitts – fuer die Live-Uhr im Frontend. */
    #[Groups(['job:read'])]
    public function getRunningSince(): ?\DateTimeImmutable
    {
        return $this->getRunningEntry()?->getStartedAt();
    }

    /** Erster tatsaechlicher Arbeitsbeginn. */
    #[Groups(['job:read'])]
    public function getFirstStartedAt(): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($this->timeEntries as $entry) {
            if (null === $first || $entry->getStartedAt() < $first) {
                $first = $entry->getStartedAt();
            }
        }

        return $first;
    }

    #[Groups(['job:read'])]
    public function getEntryCount(): int
    {
        return $this->timeEntries->count();
    }

    /** An wie vielen verschiedenen Tagen daran gearbeitet wurde. */
    #[Groups(['job:read'])]
    public function getWorkedDays(): int
    {
        $days = [];
        foreach ($this->timeEntries as $entry) {
            $days[$entry->getStartedAt()->format('Y-m-d')] = true;
        }

        return \count($days);
    }
}
