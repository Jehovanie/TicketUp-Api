<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne de commande : un tarif, une quantité.
 *
 * Le nom et le prix du tarif sont **copiés** au moment de l'achat : modifier
 * ou renommer le tarif ensuite ne change pas ce que l'acheteur a payé. Le tarif
 * reste référencé (`ON DELETE RESTRICT`) : un tarif vendu ne peut plus être
 * supprimé.
 *
 * CHECK posés par la migration : prix ≥ 0, quantité > 0, total = prix × quantité.
 */
#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Table(name: 'order_item')]
#[ORM\UniqueConstraint(name: 'uniq_item_order_type', columns: ['order_id', 'ticket_type_id'])]
#[ORM\Index(name: 'idx_item_ticket_type', columns: ['ticket_type_id'])]
#[ORM\HasLifecycleCallbacks]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private ?TicketOrder $order = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?TicketType $ticketType = null;

    #[ORM\Column(length: 50)]
    private ?string $ticketTypeName = null;

    #[ORM\Column]
    private int $unitPrice = 0;

    #[ORM\Column]
    private int $quantity = 0;

    #[ORM\Column]
    private int $lineTotal = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Ticket> */
    #[ORM\OneToMany(targetEntity: Ticket::class, mappedBy: 'orderItem', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $tickets;

    private function __construct()
    {
        $this->tickets = new ArrayCollection();
    }

    /** Crée une ligne en figeant le nom et le prix actuels du tarif. */
    public static function fromTicketType(TicketType $ticketType, int $quantity): self
    {
        $item = new self();
        $item->ticketType = $ticketType;
        $item->ticketTypeName = (string) $ticketType->getName();
        $item->unitPrice = (int) $ticketType->getPrix();
        $item->quantity = $quantity;
        $item->lineTotal = $item->unitPrice * $quantity;

        return $item;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?TicketOrder
    {
        return $this->order;
    }

    public function setOrder(TicketOrder $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getTicketType(): ?TicketType
    {
        return $this->ticketType;
    }

    public function getTicketTypeName(): ?string
    {
        return $this->ticketTypeName;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getLineTotal(): int
    {
        return $this->lineTotal;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Ticket> */
    public function getTickets(): Collection
    {
        return $this->tickets;
    }

    /**
     * Émet un billet par place. Sans effet si les billets existent déjà : une
     * confirmation de paiement rejouée ne doit pas les dupliquer.
     *
     * @param callable(): string $codeGenerator
     */
    public function issueTickets(callable $codeGenerator): void
    {
        if (!$this->tickets->isEmpty()) {
            return;
        }

        for ($i = 0; $i < $this->quantity; $i++) {
            $this->tickets->add(new Ticket($this, $codeGenerator()));
        }
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
