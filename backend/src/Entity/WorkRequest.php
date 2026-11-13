<?php

namespace App\Entity;

use App\Enum\WorkRequestStatus;
use App\Repository\WorkRequestRepository;
use App\Util\LocalTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * F7/F8 – "Brauche Arbeit": Ein Arbeiter meldet, ab wann er wieder eine
 * neue Arbeit braucht. Frueheste Anfrage ist der naechste Kalendertag,
 * damit noch ein Kunde in die Werkstatt bestellt werden kann.
 *
 * Zaehlregel fuer die Statistik: Jede abgeschickte Anfrage ist genau ein
 * Datensatz. Aendert der Mitarbeiter eine noch offene Anfrage (neues Datum),
 * wird derselbe Datensatz angepasst (reschedule) – es entsteht keine zweite
 * Anfrage. Zurueckgezogene Anfragen zaehlen nicht als Arbeitsanfrage.
 *
 * Nach dem endgueltigen Loeschen eines Benutzers bleibt die Anfrage fuer die
 * Statistik erhalten (user = null, Name in userName).
 */
#[ORM\Entity(repositoryClass: WorkRequestRepository::class)]
class WorkRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['request:read'])]
    private ?User $user;

    /** Name zum Zeitpunkt der Anfrage – bleibt nach dem Loeschen des Benutzers lesbar. */
    #[ORM\Column(length: 201, options: ['default' => ''])]
    private string $userName;

    /** Zeitpunkt, ab dem neue Arbeit gebraucht wird. */
    #[ORM\Column]
    #[Groups(['request:read'])]
    private \DateTimeImmutable $neededAt;

    #[ORM\Column(type: 'string', length: 20, enumType: WorkRequestStatus::class)]
    #[Groups(['request:read'])]
    private WorkRequestStatus $status = WorkRequestStatus::Open;

    #[ORM\Column]
    #[Groups(['request:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * @throws \DomainException wenn die Vorlaufzeit (F8) nicht eingehalten ist
     */
    public function __construct(User $user, \DateTimeImmutable $neededAt, ?\DateTimeImmutable $now = null)
    {
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $neededAt = LocalTime::of($neededAt);

        if ($neededAt < self::earliestFor($this->createdAt)) {
            throw new \DomainException('Neue Arbeit kann fruehestens fuer morgen angefragt werden.');
        }

        $this->user = $user;
        $this->userName = $user->getFullName();
        $this->neededAt = $neededAt;
    }

    /**
     * Offene Anfrage auf einen anderen Zeitpunkt aendern – bleibt dieselbe
     * Anfrage (zaehlt in der Statistik einmal).
     *
     * @throws \DomainException wenn die Vorlaufzeit (F8) nicht eingehalten ist
     */
    public function reschedule(\DateTimeImmutable $neededAt, ?\DateTimeImmutable $now = null): static
    {
        if (!$this->isOpen()) {
            throw new \DomainException('Diese Anfrage ist nicht mehr offen.');
        }

        $neededAt = LocalTime::of($neededAt);
        if ($neededAt < self::earliestFor($now ?? new \DateTimeImmutable())) {
            throw new \DomainException('Neue Arbeit kann fruehestens fuer morgen angefragt werden.');
        }

        $this->neededAt = $neededAt;

        return $this;
    }

    /** F8 – fruehester erlaubter Zeitpunkt: der naechste Tag, 00:00. */
    public static function earliestFor(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return LocalTime::of($now)->modify('tomorrow');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** null, wenn der Benutzer inzwischen geloescht wurde. */
    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getUserName(): string
    {
        return $this->user?->getFullName() ?? $this->userName;
    }

    public function getNeededAt(): \DateTimeImmutable
    {
        return $this->neededAt;
    }

    public function getStatus(): WorkRequestStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return WorkRequestStatus::Open === $this->status;
    }

    public function fulfil(): static
    {
        $this->status = WorkRequestStatus::Fulfilled;

        return $this;
    }

    public function withdraw(): static
    {
        $this->status = WorkRequestStatus::Withdrawn;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
