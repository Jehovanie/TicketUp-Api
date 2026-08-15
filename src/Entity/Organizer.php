<?php

namespace App\Entity;

use App\Enum\OrganizerRole;
use App\Repository\OrganizerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: OrganizerRepository::class)]
#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\GetCollection(
            normalizationContext :  [ "groups" => [ 'organizer:lists'] ],
        ),
        // Mes organisations : même ressource, mais restreinte à l'appartenance de
        // l'appelant et sérialisée à la main par le contrôleur (d'où `read: false`
        // et l'absence de `normalizationContext`, sans effet sur ce chemin).
        new \ApiPlatform\Metadata\GetCollection(
            uriTemplate: '/organizers/me',
            controller: \App\Controller\Api\Membership\GetMyOrganizersController::class,
            read: false,
            paginationEnabled: false,
            openapi: new OpenApiOperation(
                summary: 'Mes organisations',
                description: "Organisations auxquelles l'utilisateur connecté appartient, avec son rôle dans chacune. "
                    . "Vue réduite de `/api/organizers` : `id`, `name`, `role`, `roleLabel`, `isOwner`. "
                    . "Requiert un jeton JWT ; répond `401` sans authentification.",
                parameters: [
                    new Parameter(
                        name: 'page',
                        in: 'query',
                        required: false,
                        description: 'Numéro de page (défaut : 1)',
                        schema: ['type' => 'integer', 'default' => 1]
                    ),
                    new Parameter(
                        name: 'itemsPerPage',
                        in: 'query',
                        required: false,
                        description: 'Nombre d’éléments par page (défaut : 10, maximum : 20)',
                        schema: ['type' => 'integer', 'default' => 10, 'maximum' => 20]
                    ),
                ]
            ),
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate:'/organizer/{id}',
            normalizationContext :  [ "groups" => [ 'organizer:lists', 'organizer:details'] ],
        ),
    ]
)]
class Organizer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['events:details', 'organizer:lists'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['events:details', 'events:create', 'organizer:lists'])]
    private ?string $name = null;

    #[ORM\Column(length: 100)]
    #[Groups(['events:details', 'events:create', 'organizer:lists'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['events:details', 'events:create', 'organizer:details'])]
    private ?string $phone = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['events:details', 'events:create', 'organizer:details'])]
    private ?string $website = null;

    #[ORM\Column]
    #[Groups(['organizer:details'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['organizer:details'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'organizer', orphanRemoval: true)]
    private Collection $events;

    /**
     * Membres de l'organisation, avec leur rôle.
     *
     * @var Collection<int, OrganizerMembership>
     */
    #[ORM\OneToMany(mappedBy: 'organizer', targetEntity: OrganizerMembership::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $memberships;

    public function __construct()
    {
        $this->events = new ArrayCollection();
        $this->memberships = new ArrayCollection();

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

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

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

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

    /**
     * @return Collection<int, Event>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(Event $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setOrganizer($this);
        }

        return $this;
    }

    public function removeEvent(Event $event): static
    {
        if ($this->events->removeElement($event)) {
            // set the owning side to null (unless already changed)
            if ($event->getOrganizer() === $this) {
                $event->setOrganizer(null);
            }
        }

        return $this;
    }

    // ------------------------------------------------------------- Membres

    /** @return Collection<int, OrganizerMembership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(OrganizerMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setOrganizer($this);
        }

        return $this;
    }

    public function removeMembership(OrganizerMembership $membership): static
    {
        if ($this->memberships->removeElement($membership) && $membership->getOrganizer() === $this) {
            $membership->setOrganizer(null);
        }

        return $this;
    }

    /**
     * Responsables de l'organisation. Plusieurs sont possibles (co-fondateurs) ;
     * `getOwner()` renvoie le premier pour les affichages qui n'en veulent qu'un.
     *
     * @return User[]
     */
    public function getOwners(): array
    {
        return array_values(array_filter(array_map(
            static fn (OrganizerMembership $m) => $m->isOwner() ? $m->getUser() : null,
            $this->memberships->toArray()
        )));
    }

    public function getOwner(): ?User
    {
        return $this->getOwners()[0] ?? null;
    }

    /** @return User[] */
    public function getMembers(): array
    {
        return array_values(array_filter(
            $this->memberships->map(static fn (OrganizerMembership $m) => $m->getUser())->toArray()
        ));
    }

    public function hasMember(User $user): bool
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
