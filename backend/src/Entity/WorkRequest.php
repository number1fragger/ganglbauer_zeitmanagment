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
 * eine neue Arbeit braucht. Die Anforderung muss spaetestens am Vortag
 * kommen, damit noch ein Kunde in die Werkstatt geholt werden kann –
 * "morgen frueh" geht also immer, "heute Nachmittag" nie.
 */
#[ORM\Entity(repositoryClass: WorkRequestRepository::class)]
class WorkRequest
{
    /** Mindestvorlauf in Kalendertagen. */
    public const MIN_LEAD_TIME_DAYS = 1;

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
        $this->neededAt = (new \DateTimeImmutable('+1 day'))->setTime(7, 0);
        $this->createdAt = new \DateTimeImmutable();
    }

    /** F8 – fruehestens der naechste Kalendertag. */
    #[Assert\Callback]
    public function validateLeadTime(ExecutionContextInterface $context): void
    {
        if ($this->neededAt < $this->earliestNeededAt()) {
            $context->buildViolation('Neue Arbeit kann fruehestens fuer den naechsten Tag angefordert werden.')
                ->atPath('neededAt')
                ->addViolation();
        }
    }

    /** Beginn des naechsten Tages, gerechnet ab dem Zeitpunkt der Anforderung. */
    public function earliestNeededAt(): \DateTimeImmutable
    {
        return $this->createdAt
            ->modify(sprintf('+%d day', self::MIN_LEAD_TIME_DAYS))
            ->setTime(0, 0);
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
