<?php

namespace App\Entity;

use App\Enum\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Billet émis : une place, un code unique (contenu du QR code).
 *
 * Créé par `OrderItem::issueTickets()` quand la commande est payée (ou
 * immédiatement si elle est gratuite).
 *
 * CHECK posés par la migration : statut connu ; un billet `used` a un `used_at`.
 */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
#[ORM\UniqueConstraint(name: 'uniq_ticket_code', columns: ['code'])]
#[ORM\Index(name: 'idx_ticket_order_item', columns: ['order_item_id'])]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tickets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrderItem $orderItem;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: TicketStatus::class)]
    private TicketStatus $status = TicketStatus::VALID;

    /** Nom du porteur, pour les billets nominatifs (facultatif). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $holderName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(OrderItem $orderItem, string $code)
    {
        $this->orderItem = $orderItem;
        $this->code = $code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderItem(): OrderItem
    {
        return $this->orderItem;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): TicketStatus
    {
        return $this->status;
    }

    public function getHolderName(): ?string
    {
        return $this->holderName;
    }

    public function setHolderName(?string $holderName): static
    {
        $this->holderName = $holderName;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
