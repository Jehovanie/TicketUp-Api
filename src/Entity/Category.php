<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\GetCollection(
            uriTemplate: '/categories',
            controller: \App\Controller\Api\Category\GetCategoriesController::class,
            read: false,
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate:'/categories/{id}',
            normalizationContext :  [ "groups" => [ 'category:lists', 'category:details'] ],
        ),
    ]
)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['events:lists', 'events:details', 'category:lists'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['events:lists', 'events:details', 'events:create', 'category:lists'])]
    private ?string $name = null;

    #[ORM\Column(length: 50)]
    #[Groups(['events:details', 'events:create', 'category:lists'])]
    private ?string $color = null;

    #[ORM\Column]
    #[Groups(['category:details'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['category:details'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'category')]
    private Collection $events;

    public function __construct()
    {
        $this->events = new ArrayCollection();

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

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

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
            $event->setCategory($this);
        }

        return $this;
    }

    public function removeEvent(Event $event): static
    {
        if ($this->events->removeElement($event)) {
            // set the owning side to null (unless already changed)
            if ($event->getCategory() === $this) {
                $event->setCategory(null);
            }
        }

        return $this;
    }
}
