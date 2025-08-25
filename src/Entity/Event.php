<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;

use Symfony\Component\Serializer\Annotation\Groups;


#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ApiResource(
    paginationItemsPerPage: 10,
    paginationMaximumItemsPerPage: 20,
    paginationClientItemsPerPage: true,
    operations: [
        new \ApiPlatform\Metadata\GetCollection(
            normalizationContext: ["groups" => ['events:lists']],
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate: '/events/{id}',
            normalizationContext: ["groups" => ['events:lists', 'events:details']],
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate: '/admin/events/{id}',
            controller: \App\Controller\Admin\Event\AdminEventDetailsController::class,
            read: false, // we don’t want to automatically fetch the entity
            openapi: new OpenApiOperation(
                summary: 'Get admin details of a specific event',
                description: 'Custom admin event logic',
                parameters: [
                    new Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'The ID of the event',
                        schema: [
                            'type' => 'integer'
                        ]
                    )
                ]
            )
        ),
        new \ApiPlatform\Metadata\Post(
            uriTemplate: '/events',
            denormalizationContext: ["groups" => ['events:create']],
            security: "is_granted('ROLE_USER')",
        ),
    ]
)]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['events:lists'])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Groups(['events:lists', 'events:create'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['events:details', 'events:create'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['events:lists', 'events:create'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column]
    #[Groups(['events:lists', 'events:create'])]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(type: Types::ARRAY)]

    private array $imageUrl = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    #[Groups(['events:lists'])]
    private ?bool $status = null;

    #[ORM\ManyToOne(inversedBy: 'events', cascade: ['persist'])]
    #[Groups(['events:lists','events:details', 'events:create'])]
    private ?Category $category = null;

    #[ORM\ManyToOne(inversedBy: 'events', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['events:details', 'events:create'])]
    private ?Organizer $organizer = null;

    #[ORM\ManyToOne(inversedBy: 'events', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['events:lists', 'events:create'])]
    private ?Location $location = null;

    /**
     * @var Collection<int, TicketType>
     */
    #[ORM\OneToMany(targetEntity: TicketType::class, mappedBy: 'event', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[Groups(['events:lists', 'events:details', 'events:create'])]
    private Collection $ticket_type;

    #[ORM\Column(length: 25)]
    #[Groups(['events:lists'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::GUID)]
    #[Groups(['events:lists'])]
    private ?string $uuid = null;

    public function __construct()
    {
        $this->setUpdatedAt(new \DateTimeImmutable());
        if ($this->getId() === null) {
            $this->setCreatedAt(new \DateTimeImmutable());
            $this->setStatus(false);
        }
        $this->ticket_type = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }


    public function getImageUrl(): array
    {
        return $this->imageUrl;
    }

    public function setImageUrl(array $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

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

    public function isStatus(): ?bool
    {
        return $this->status;
    }

    public function setStatus(bool $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

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

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;

        return $this;
    }

    /**
     * @return Collection<int, TicketType>
     */
    public function getTicketType(): Collection
    {
        return $this->ticket_type;
    }

    public function addTicketType(TicketType $ticketType): static
    {
        if (!$this->ticket_type->contains($ticketType)) {
            $this->ticket_type->add($ticketType);
            $ticketType->setEvent($this);
        }

        return $this;
    }

    public function removeTicketType(TicketType $ticketType): static
    {
        if ($this->ticket_type->removeElement($ticketType)) {
            // set the owning side to null (unless already changed)
            if ($ticketType->getEvent() === $this) {
                $ticketType->setEvent(null);
            }
        }

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }
}
