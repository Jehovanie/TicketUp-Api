<?php

namespace App\Entity;

use App\Repository\TicketTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Entity(repositoryClass: TicketTypeRepository::class)]
class TicketType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['events:lists'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Groups(['events:lists', 'events:create'])]
    private ?string $name = null;

    #[ORM\Column]
    #[Groups(['events:lists', 'events:create'])]
    private ?int $prix = null;

    #[ORM\Column]
    #[Groups(['events:lists', 'events:create'])]
    private ?int $quantite_max = null;

    /**
     * Places prises par les commandes en attente de paiement ou payées.
     *
     * Modifiée **uniquement** par une requête SQL conditionnelle d'`OrderService`
     * (`quantite_reservee + n <= quantite_max`), jamais par l'ORM : une lecture
     * suivie d'une réécriture perdrait des ventes simultanées. D'où l'absence de
     * setter et `updatable: false`. La valeur en mémoire peut donc être en retard
     * sur la base. Un CHECK (posé par la migration) la borne entre 0 et `quantite_max`.
     */
    #[ORM\Column(updatable: false, options: ['default' => 0])]
    private int $quantiteReservee = 0;

    #[ORM\ManyToOne(inversedBy: 'ticket_type')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Event $event = null;

    #[ORM\Column]
    #[Groups(['events:details'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['events:details'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->setUpdatedAt(new \DateTimeImmutable());
        if( $this->getId() === null ) {
            $this->setCreatedAt(new \DateTimeImmutable());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPrix(): ?int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): static
    {
        $this->prix = $prix;

        return $this;
    }

    public function getQuantiteMax(): ?int
    {
        return $this->quantite_max;
    }

    public function setQuantiteMax(int $quantite_max): static
    {
        $this->quantite_max = $quantite_max;

        return $this;
    }

    public function getQuantiteReservee(): int
    {
        return $this->quantiteReservee;
    }

    /** Places encore en vente, exposées au catalogue. */
    #[Groups(['events:lists'])]
    #[SerializedName('quantite_disponible')]
    public function getQuantiteDisponible(): int
    {
        return max(0, (int) $this->quantite_max - $this->quantiteReservee);
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
