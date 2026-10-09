<?php

namespace App\Entity;

use App\Repository\TimeEntryRepository;
use App\Util\LocalTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Ein Arbeitsabschnitt: die Zeit von "Starten"/"Fortsetzen" bis
 * "Pausieren"/"Abschliessen". Die Ist-Zeit einer Arbeit ist die Summe
 * aller Abschnitte – Pausen, Naechte und Wochenenden liegen zwischen
 * zwei Abschnitten und zaehlen daher nicht.
 *
 * Ein Abschnitt, der nie gestoppt wurde (Arbeiter hat vergessen zu
 * pausieren), zaehlt hoechstens bis zur Kappungsgrenze und wird beim
 * naechsten Zugriff automatisch dort beendet (autoClosed = true).
 */
#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Index(name: 'idx_entry_user_end', columns: ['user_id', 'ended_at'])]
#[ORM\Index(name: 'idx_entry_job_end', columns: ['job_id', 'ended_at'])]
class TimeEntry
{
    /** Ein vergessener Abschnitt laeuft hoechstens bis zu dieser Uhrzeit am Starttag ... */
    public const AUTO_CLOSE_HOUR = 20;

    /** ... mindestens aber so viele Stunden ab dem Start (fuer spaete Starts). */
    public const MAX_OPEN_HOURS = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['job:entries'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['job:entries'])]
    private User $user;

    #[ORM\ManyToOne(inversedBy: 'timeEntries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Job $job;

    #[ORM\Column]
    #[Groups(['job:entries'])]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['job:entries'])]
    private ?\DateTimeImmutable $endedAt = null;

    /** Vom System an der Kappungsgrenze beendet, nicht vom Arbeiter. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['job:entries'])]
    private bool $autoClosed = false;

    public function __construct(User $user, Job $job, ?\DateTimeImmutable $startedAt = null)
    {
        $this->user = $user;
        $this->startedAt = LocalTime::of($startedAt ?? new \DateTimeImmutable());
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

    public function isAutoClosed(): bool
    {
        return $this->autoClosed;
    }

    /**
     * Beendet den Abschnitt. Ein Ende vor dem Start ergibt null Sekunden –
     * es wird keine kuenstliche Mindestzeit gutgeschrieben. Ein bereits
     * beendeter Abschnitt bleibt unveraendert (idempotent).
     */
    public function stop(?\DateTimeImmutable $at = null): static
    {
        if (null !== $this->endedAt) {
            return $this;
        }

        $end = LocalTime::of($at ?? new \DateTimeImmutable());
        $cap = $this->autoCloseAt();

        if ($end > $cap) {
            $end = $cap;
            $this->autoClosed = true;
        }

        $this->endedAt = $end < $this->startedAt ? $this->startedAt : $end;

        return $this;
    }

    /** Beendet einen vergessenen Abschnitt, falls die Kappungsgrenze vorbei ist. */
    public function closeIfStale(\DateTimeImmutable $now): bool
    {
        if (null !== $this->endedAt || $now <= $this->autoCloseAt()) {
            return false;
        }

        $this->stop($now);

        return true;
    }

    /**
     * Spaetestes Ende eines offenen Abschnitts: 20:00 am Starttag,
     * mindestens aber vier Stunden nach dem Start.
     */
    public function autoCloseAt(): \DateTimeImmutable
    {
        $evening = $this->startedAt->setTime(self::AUTO_CLOSE_HOUR, 0);
        $latest = $this->startedAt->modify(sprintf('+%d hours', self::MAX_OPEN_HOURS));

        return max($evening, $latest);
    }

    #[Groups(['job:entries'])]
    public function isRunning(): bool
    {
        return null === $this->endedAt;
    }

    /**
     * Dauer in Sekunden. Laufende Abschnitte zaehlen bis jetzt, aber nie
     * ueber die Kappungsgrenze hinaus.
     */
    #[Groups(['job:entries'])]
    public function getDurationSeconds(?\DateTimeImmutable $now = null): int
    {
        $end = $this->endedAt ?? min($now ?? new \DateTimeImmutable(), $this->autoCloseAt());

        return max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }

    /** Volle Minuten – fuer Anzeigen; summiert wird immer in Sekunden. */
    public function getDurationMinutes(): int
    {
        return intdiv($this->getDurationSeconds(), 60);
    }
}
