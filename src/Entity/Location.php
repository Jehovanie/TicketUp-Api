<?php

namespace App\Entity;

use App\Repository\LocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\GetCollection(
            normalizationContext :  [ "groups" => [ 'location:lists'] ],
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate:'/location/{id}',
            normalizationContext :  [ "groups" => [ 'location:lists', 'location:details'] ],
        ),
    ]
)]
class Location
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['location:lists', 'events:lists'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['location:lists', 'events:lists', 'events:create'])]
    private ?string $name = null;

    #[ORM\Column]
    #[Groups(['location:lists', 'events:lists', 'events:create'])]
    private ?int $size = null;

    #[ORM\Column]
    #[Groups(['location:details'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['location:details'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Événements rattachés. Lecture seule en pratique : le rattachement se fait
     * côté `Event` (colonne `event.location_id`, obligatoire).
     *
     * Pas d'`orphanRemoval` ni de cascade : supprimer un lieu ne doit jamais
     * emporter ses événements (et leurs commandes). La base refuse la
     * suppression tant que des événements y sont rattachés ;
     * `IntegrityConstraintSubscriber` en fait une réponse `409` lisible.
     *
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'location')]
    private Collection $events;

    public function __construct()
    {
        $this->setUpdatedAt(new \DateTimeImmutable());
        if( $this->getId() === null ) {
            $this->setCreatedAt(new \DateTimeImmutable());
        }
        $this->events = new ArrayCollection();
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

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

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
            $event->setLocation($this);
        }

        return $this;
    }

    /**
     * Retire l'événement de la collection en mémoire, **sans** le supprimer ni
     * le détacher en base : `event.location_id` est obligatoire. Pour déplacer un
     * événement vers un autre lieu, appeler `$event->setLocation($autre)`.
     */
    public function removeEvent(Event $event): static
    {
        $this->events->removeElement($event);

        return $this;
    }
}
