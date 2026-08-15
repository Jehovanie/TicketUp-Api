<?php

namespace App\Entity;

use App\Enum\OrganizerRole;
use App\Repository\OrganizerMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Appartenance d'un utilisateur à une organisation, avec son rôle.
 *
 * Table de liaison portée par une entité (et non un simple `ManyToMany`) parce
 * que la relation porte des données propres : le rôle, et la date d'entrée.
 * C'est ce qui permet à une même personne de diriger plusieurs organisations
 * indépendantes, avec un rôle distinct dans chacune.
 *
 * L'unicité est posée sur le couple (utilisateur, organisation) : un rôle par
 * organisation. Pour autoriser plus tard le cumul de rôles, il suffira d'étendre
 * cette contrainte à (utilisateur, organisation, rôle).
 */
#[ORM\Entity(repositoryClass: OrganizerMembershipRepository::class)]
#[ORM\Table(name: 'organizer_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_organizer', columns: ['user_id', 'organizer_id'])]
#[ORM\Index(name: 'idx_membership_organizer_role', columns: ['organizer_id', 'role'])]
#[ORM\HasLifecycleCallbacks]
class OrganizerMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['membership:lists'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organizer $organizer = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrganizerRole::class)]
    #[Groups(['membership:lists'])]
    private OrganizerRole $role = OrganizerRole::MEMBER;

    #[ORM\Column]
    #[Groups(['membership:lists'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getOrganizer(): ?Organizer
    {
        return $this->organizer;
    }

    public function setOrganizer(?Organizer $organizer): static
    {
        $this->organizer = $organizer;
        return $this;
    }

    public function getRole(): OrganizerRole
    {
        return $this->role;
    }

    public function setRole(OrganizerRole $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function isOwner(): bool
    {
        return $this->role === OrganizerRole::OWNER;
    }

    public function hasAtLeast(OrganizerRole $role): bool
    {
        return $this->role->isAtLeast($role);
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
