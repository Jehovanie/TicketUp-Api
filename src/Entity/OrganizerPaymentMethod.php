<?php

namespace App\Entity;

use App\Enum\MobileMoneyProvider;
use App\Repository\OrganizerPaymentMethodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compte mobile money sur lequel une organisation encaisse ses billets.
 *
 * Une organisation a au plus un compte par opérateur (MVola, Orange Money,
 * Airtel Money) et doit en avoir au moins un à sa création. Le nom du titulaire
 * est conservé en plus du numéro : c'est lui que l'acheteur voit s'afficher
 * chez l'opérateur avant de confirmer son transfert, et ce qui lui permet de
 * détecter une erreur de destinataire.
 *
 * Le numéro est stocké normalisé (`+261XXXXXXXXX`) pour que les comparaisons et
 * l'affichage ne dépendent pas de la manière dont il a été saisi.
 */
#[ORM\Entity(repositoryClass: OrganizerPaymentMethodRepository::class)]
#[ORM\Table(name: 'organizer_payment_method')]
#[ORM\UniqueConstraint(name: 'uniq_payment_method_organizer_provider', columns: ['organizer_id', 'provider'])]
#[ORM\HasLifecycleCallbacks]
class OrganizerPaymentMethod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paymentMethods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organizer $organizer = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: MobileMoneyProvider::class)]
    private ?MobileMoneyProvider $provider = null;

    #[ORM\Column(length: 20)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 100)]
    private ?string $accountName = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganizer(): ?Organizer
    {
        return $this->organizer;
    }

    public function setOrganizer(?Organizer $organizer): static
    {
        $this->organizer = $organizer;
        return $this;
    }

    public function getProvider(): ?MobileMoneyProvider
    {
        return $this->provider;
    }

    public function setProvider(MobileMoneyProvider $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(string $accountName): static
    {
        $this->accountName = $accountName;
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
