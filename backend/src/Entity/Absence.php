<?php

namespace App\Entity;

use App\Enum\AbsenceType;
use App\Exception\InvalidInputException;
use App\Repository\AbsenceRepository;
use App\Util\LocalTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Abwesenheit eines Mitarbeiters (Urlaub, Arzttermin, ...). Eingetragen und
 * verwaltet nur von Chef bzw. Sekretaerin.
 *
 * Ganztaegige Abwesenheiten beginnen um 00:00 des ersten Tags und enden um
 * 00:00 nach dem letzten Tag (Ende exklusiv) – so ueberschneiden sie sich mit
 * allem, was an diesen Tagen eingeplant ist, ohne kuenstliche Uhrzeiten.
 *
 * Datenschutz: Die interne Notiz und wer den Eintrag angelegt hat, liefert
 * die API nur an Berechtigte aus (Gruppe absence:detail). In Kalender-
 * ansichten steht nur die Art, z. B. "Arzttermin".
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Index(name: 'idx_absence_user_range', columns: ['user_id', 'starts_at', 'ends_at'])]
class Absence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['absence:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['absence:read'])]
    private User $user;

    #[ORM\Column(type: 'string', length: 30, enumType: AbsenceType::class)]
    #[Groups(['absence:read'])]
    private AbsenceType $type;

    #[ORM\Column]
    #[Groups(['absence:read'])]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column]
    #[Groups(['absence:read'])]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['absence:read'])]
    private bool $allDay = false;

    /** Interne Notiz – nur fuer Chef/Sekretaerin. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['absence:detail'])]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['absence:detail'])]
    private ?User $createdBy = null;

    #[ORM\Column]
    #[Groups(['absence:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['absence:detail'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, AbsenceType $type, ?User $createdBy, ?\DateTimeImmutable $now = null)
    {
        $this->user = $user;
        $this->type = $type;
        $this->createdBy = $createdBy;
        $this->createdAt = LocalTime::of($now ?? new \DateTimeImmutable());
        $this->startsAt = $this->createdAt;
        $this->endsAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getType(): AbsenceType
    {
        return $this->type;
    }

    public function setType(AbsenceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** Anzeige im Kalender, z. B. "Urlaub". */
    #[Groups(['absence:read'])]
    public function getLabel(): string
    {
        return $this->type->label();
    }

    /** Ganztaegig von $firstDay bis einschliesslich $lastDay. */
    public function setDays(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): static
    {
        $start = LocalTime::of($firstDay)->setTime(0, 0);
        $end = LocalTime::of($lastDay)->setTime(0, 0)->modify('+1 day');

        if ($end <= $start) {
            throw new InvalidInputException('Der letzte Tag darf nicht vor dem ersten liegen.');
        }

        $this->startsAt = $start;
        $this->endsAt = $end;
        $this->allDay = true;

        return $this;
    }

    /** Stundenweise, z. B. Arzttermin 09:00–10:30. */
    public function setPeriod(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): static
    {
        $startsAt = LocalTime::of($startsAt);
        $endsAt = LocalTime::of($endsAt);

        if ($endsAt <= $startsAt) {
            throw new InvalidInputException('Das Ende der Abwesenheit muss nach dem Beginn liegen.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->allDay = false;

        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    /** Letzter Abwesenheitstag (bei ganztaegigen Eintraegen inklusive). */
    #[Groups(['absence:read'])]
    public function getLastDay(): string
    {
        return ($this->allDay ? $this->endsAt->modify('-1 day') : $this->endsAt)->format('Y-m-d');
    }

    public function overlaps(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $this->startsAt < $to && $from < $this->endsAt;
    }

    /** "20.10.–24.10." bzw. "Mo 20.10., 09:00–10:30" */
    public function describePeriod(): string
    {
        if ($this->allDay) {
            $last = $this->endsAt->modify('-1 day');

            return $last->format('Y-m-d') === $this->startsAt->format('Y-m-d')
                ? $this->startsAt->format('d.m.')
                : $this->startsAt->format('d.m.').'–'.$last->format('d.m.');
        }

        return $this->startsAt->format('Y-m-d') === $this->endsAt->format('Y-m-d')
            ? $this->startsAt->format('d.m., H:i').'–'.$this->endsAt->format('H:i')
            : $this->startsAt->format('d.m., H:i').' – '.$this->endsAt->format('d.m., H:i');
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $note = null !== $note ? trim($note) : null;
        $this->note = '' === $note ? null : $note;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->updatedAt = LocalTime::of($now);

        return $this;
    }
}
