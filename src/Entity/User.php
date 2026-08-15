<?php

namespace App\Entity;

use App\Enum\OrganizerRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * Rôle global, indépendant des organisations : le fondateur de la
     * plateforme. Il court-circuite les contrôles d'appartenance.
     */
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** @var string The hashed password */
    #[Assert\NotCompromisedPassword]
    #[ORM\Column]
    private ?string $password = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50)]
    private ?string $firstname = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50)]
    private ?string $lastname = null;

    #[Assert\Length(max: 30)]
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    // Ex: "fr", "en" (ISO 639-1)
    #[Assert\Length(max: 10)]
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = null;

    // Interprété comme "date d'activation"
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $is_active = null;

    /**
     * Organisations auxquelles l'utilisateur appartient, avec son rôle dans
     * chacune. Un même utilisateur peut en diriger plusieurs.
     *
     * @var Collection<int, OrganizerMembership>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: OrganizerMembership::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $memberships;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) ($this->email ?? '');
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }
    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }
    public function eraseCredentials(): void {}

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }
    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }
    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }
    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }
    public function setLanguage(?string $language): static
    {
        $this->language = $language;
        return $this;
    }

    // "Date d'activation"
    public function getIsActive(): ?\DateTimeInterface
    {
        return $this->is_active;
    }
    public function setIsActive(?\DateTimeInterface $is_active): static
    {
        $this->is_active = $is_active;
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

    // ------------------------------------------------- Organisations / rôles

    /** @return Collection<int, OrganizerMembership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(OrganizerMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setUser($this);
        }

        return $this;
    }

    public function removeMembership(OrganizerMembership $membership): static
    {
        if ($this->memberships->removeElement($membership) && $membership->getUser() === $this) {
            $membership->setUser(null);
        }

        return $this;
    }

    /**
     * Organisations de l'utilisateur.
     *
     * @return Organizer[]
     */
    public function getOrganizers(): array
    {
        return array_values(array_filter(
            $this->memberships->map(static fn (OrganizerMembership $m) => $m->getOrganizer())->toArray()
        ));
    }

    public function getMembershipFor(Organizer $organizer): ?OrganizerMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getOrganizer()?->getId() === $organizer->getId()) {
                return $membership;
            }
        }

        return null;
    }

    public function getRoleIn(Organizer $organizer): ?OrganizerRole
    {
        return $this->getMembershipFor($organizer)?->getRole();
    }

    public function isMemberOf(Organizer $organizer): bool
    {
        return $this->getMembershipFor($organizer) !== null;
    }

    /**
     * Vrai si l'utilisateur atteint au moins ce niveau dans l'organisation.
     * Le super administrateur passe partout : c'est le fondateur du site.
     */
    public function hasRoleIn(Organizer $organizer, OrganizerRole $minimum): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return (bool) $this->getRoleIn($organizer)?->isAtLeast($minimum);
    }

    public function isSuperAdmin(): bool
    {
        return in_array(self::ROLE_SUPER_ADMIN, $this->getRoles(), true);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
        $this->language ??= 'fr';
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
