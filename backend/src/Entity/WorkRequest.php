<?php

namespace App\Entity;

use App\Enum\WorkRequestStatus;
use App\Repository\WorkRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * F7/F8 – "Brauche Arbeit": Ein Arbeiter meldet, ab wann er wieder
 * eine neue Arbeit braucht. Vorlauf mindestens ein Tag, damit noch
 * ein Kunde in die Werkstatt geholt werden kann.
 */
#[ORM\Entity(repositoryClass: WorkRequestRepository::class)]
class WorkRequest
{
    public const MIN_LEAD_TIME_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'workRequests')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['request:read'])]
    private ?User $user = null;

    /** Zeitpunkt, ab dem neue Arbeit gebraucht wird. */
    #[ORM\Column]
    #[Assert\NotNull(message: 'Bitte angeben, ab wann du wieder Arbeit brauchst.')]
    #[Groups(['request:read', 'request:write'])]
    private \DateTimeImmutable $neededAt;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['request:read', 'request:write'])]
    private ?string $note = null;

    #[ORM\Column(type: 'string', length: 20, enumType: WorkRequestStatus::class)]
    #[Groups(['request:read'])]
    private WorkRequestStatus $status = WorkRequestStatus::Open;

    #[ORM\Column]
    #[Groups(['request:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->neededAt = new \DateTimeImmutable('+1 day');
        $this->createdAt = new \DateTimeImmutable();
    }

    /** F8 – Vorlaufzeit von mindestens einem Tag. */
    #[Assert\Callback]
    public function validateLeadTime(ExecutionContextInterface $context): void
    {
        $earliest = $this->createdAt->modify(sprintf('+%d hours', self::MIN_LEAD_TIME_HOURS));

        if ($this->neededAt < $earliest) {
            $context->buildViolation('Die Anforderung ist fruehestens einen Tag im Voraus moeglich.')
                ->atPath('neededAt')
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

    public function getNeededAt(): \DateTimeImmutable
    {
        return $this->neededAt;
    }

    public function setNeededAt(\DateTimeImmutable $neededAt): static
    {
        $this->neededAt = $neededAt;

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

    public function getStatus(): WorkRequestStatus
    {
        return $this->status;
    }

    public function setStatus(WorkRequestStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
