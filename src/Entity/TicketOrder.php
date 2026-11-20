<?php

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\TicketOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Commande de billets sur un événement.
 *
 * Nommée `ticket_order` et non `order` : `ORDER` est un mot réservé SQL.
 *
 * Une commande est une pièce comptable : elle n'est jamais supprimée, seulement
 * annulée ou expirée. Ce qui peut changer après l'achat est donc **figé** ici :
 * le total, les coordonnées de l'acheteur (le compte peut être supprimé,
 * `user_id` passe alors à NULL) et, dans chaque ligne, le nom et le prix du tarif.
 *
 * Les transitions de statut passent par `OrderService`, qui gère aussi le
 * compteur de places des tarifs : ne pas appeler les méthodes `mark*()` ailleurs.
 *
 * Contraintes CHECK (statut, montant, échéance d'une commande en attente) :
 * posées par la migration, Doctrine ne sait pas les décrire.
 *
 * @see docs/database/design-orders.md
 */
#[ORM\Entity(repositoryClass: TicketOrderRepository::class)]
#[ORM\Table(name: 'ticket_order')]
#[ORM\UniqueConstraint(name: 'uniq_order_reference', columns: ['reference'])]
#[ORM\UniqueConstraint(name: 'uniq_order_idempotency', columns: ['user_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_order_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_order_event_status', columns: ['event_id', 'status'])]
// Index partiel : seules les commandes en attente intéressent la purge. La clause
// est écrite sous la forme normalisée que PostgreSQL renvoie, sinon chaque
// `migrations:diff` proposerait de supprimer puis recréer l'index.
#[ORM\Index(name: 'idx_order_pending_expiry', columns: ['expires_at'], options: ['where' => "((status)::text = 'pending'::text)"])]
#[ORM\HasLifecycleCallbacks]
class TicketOrder
{
    public const CURRENCY = 'MGA';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Référence publique (`TU-XXXXXXXX`) : la seule exposée dans l'API. */
    #[ORM\Column(length: 20)]
    private ?string $reference = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Event $event = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::PENDING;

    /** Montant total en ariary, calculé à partir des lignes. */
    #[ORM\Column]
    private int $totalAmount = 0;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency = self::CURRENCY;

    #[ORM\Column(length: 180)]
    private ?string $buyerEmail = null;

    #[ORM\Column(length: 50)]
    private ?string $buyerFirstname = null;

    #[ORM\Column(length: 50)]
    private ?string $buyerLastname = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $buyerPhone = null;

    /** Clé envoyée par le client pour qu'un renvoi réseau ne crée pas de doublon. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idempotencyKey = null;

    /** Fin de la réservation des places (commande en attente uniquement). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    /** Rattache l'acheteur et fige ses coordonnées sur la commande. */
    public function setBuyer(User $user): static
    {
        $this->user = $user;
        $this->buyerEmail = $user->getEmail();
        $this->buyerFirstname = $user->getFirstname();
        $this->buyerLastname = $user->getLastname();
        $this->buyerPhone = $user->getPhone();

        return $this;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getTotalAmount(): int
    {
        return $this->totalAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBuyerEmail(): ?string
    {
        return $this->buyerEmail;
    }

    public function getBuyerFirstname(): ?string
    {
        return $this->buyerFirstname;
    }

    public function getBuyerLastname(): ?string
    {
        return $this->buyerLastname;
    }

    public function getBuyerPhone(): ?string
    {
        return $this->buyerPhone;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // -------------------------------------------------------------- Lignes

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
            $this->recalculateTotal();
        }

        return $this;
    }

    /** Nombre de billets (somme des quantités). */
    public function getTicketCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item->getQuantity();
        }

        return $count;
    }

    /** @return Ticket[] */
    public function getTickets(): array
    {
        $tickets = [];
        foreach ($this->items as $item) {
            foreach ($item->getTickets() as $ticket) {
                $tickets[] = $ticket;
            }
        }

        return $tickets;
    }

    private function recalculateTotal(): void
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getLineTotal();
        }

        $this->totalAmount = $total;
    }

    // --------------------------------------- Transitions (via OrderService)

    /**
     * Passe la commande à « payée » et émet un billet par place.
     *
     * @param callable(): string $codeGenerator
     */
    public function markPaid(\DateTimeImmutable $at, callable $codeGenerator): void
    {
        $this->status = OrderStatus::PAID;
        $this->paidAt = $at;

        foreach ($this->items as $item) {
            $item->issueTickets($codeGenerator);
        }
    }

    public function markCancelled(\DateTimeImmutable $at): void
    {
        $this->status = OrderStatus::CANCELLED;
        $this->cancelledAt = $at;
    }

    // ---------------------------------------------------------- Horodatage

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
