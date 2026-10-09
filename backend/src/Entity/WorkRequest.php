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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['request:read'])]
    private User $user;

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
        $this->neededAt = $neededAt;
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

    public function getUser(): User
    {
        return $this->user;
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
