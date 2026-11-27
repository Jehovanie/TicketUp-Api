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
            uriTemplate: '/events',
            controller: \App\Controller\Api\Event\GetEventsController::class,
            read: false,
        ),
        // Mes événements. Déclaré avant `/events/{id}` — sinon « me » serait pris
        // pour un identifiant (le contrainte `\d+` posée plus bas rend d'ailleurs
        // l'ordre non critique, mais les deux gardes valent mieux qu'une).
        new \ApiPlatform\Metadata\GetCollection(
            uriTemplate: '/events/me',
            controller: \App\Controller\Api\Event\GetMyEventsController::class,
            read: false,
            paginationEnabled: false,
            openapi: new OpenApiOperation(
                summary: 'Mes événements',
                description: "Événements portés par les organisations dont l'utilisateur connecté est membre. "
                    . "Même réponse que `/api/events`, restreinte à ce qui le concerne — brouillons compris. "
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
            uriTemplate: '/events/search',
            controller: \App\Controller\Api\Event\SearchEventController::class,
            read: false,
            openapi: new OpenApiOperation(
                summary: 'Rechercher des événements',
                description: 'Recherche des événements par catégorie, titre et dates',
                parameters: [
                    new Parameter(
                        name: 'category',
                        in: 'query',
                        required: false,
                        description: 'ID de la catégorie',
                        schema: [
                            'type' => 'integer'
                        ]
                    ),
                    new Parameter(
                        name: 'title',
                        in: 'query',
                        required: false,
                        description: 'Titre de l\'événement (recherche partielle)',
                        schema: [
                            'type' => 'string'
                        ]
                    ),
                    new Parameter(
                        name: 'startDate',
                        in: 'query',
                        required: false,
                        description: 'Date de début minimum (format: Y-m-d)',
                        schema: [
                            'type' => 'string',
                            'format' => 'date'
                        ]
                    ),
                    new Parameter(
                        name: 'endDate',
                        in: 'query',
                        required: false,
                        description: 'Date de début maximum (format: Y-m-d)',
                        schema: [
                            'type' => 'string',
                            'format' => 'date'
                        ]
                    )
                ]
            )
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate: '/events/category/{categoryId}',
            controller: \App\Controller\Api\Event\GetEventsByCategoryController::class,
            read: false,
            openapi: new OpenApiOperation(
                summary: 'Récupérer les événements par catégorie',
                description: 'Retourne tous les événements actifs d\'une catégorie spécifique',
                parameters: [
                    new Parameter(
                        name: 'categoryId',
                        in: 'path',
                        required: true,
                        description: 'ID de la catégorie',
                        schema: [
                            'type' => 'integer'
                        ]
                    )
                ]
            )
        ),
        new \ApiPlatform\Metadata\Get(
            uriTemplate: '/events/{id}',
            controller: \App\Controller\Api\Event\GetEventByIdController::class,
            read: false,
            // Sans cette contrainte, `/events/{id}` attrape tout ce qui suit
            // `/events/` : les routes littérales (`/events/me`, `/events/search`)
            // ne s'en sortaient que par leur ordre de déclaration.
            requirements: ['id' => '\d+'],
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
            controller: \App\Controller\Api\Event\CreateEventController::class,
            read: false,
            deserialize: false,
            validate: false,
            write: false,
            inputFormats: ['json' => ['application/json'], 'multipart' => ['multipart/form-data']],
            denormalizationContext: ["groups" => ['events:create']],
            openapi: new \ApiPlatform\OpenApi\Model\Operation(
                summary: 'Créer un événement (avec 0 à 3 images)',
                description: "Requiert un jeton JWT et d'être **membre** de l'organisation visée (`403` sinon).\n\n"
                    . "L'organisation n'est **pas** créée ici : `organizer.id` est obligatoire "
                    . "(`400` sinon). Créez-la d'abord via `POST /api/organizers`. Catégorie et lieu sont "
                    . "liés par `id` ou créés à partir des champs fournis.\n\n"
                    . "**Images** : envoyer en `multipart/form-data` un champ `data` (l'objet événement en JSON) "
                    . "et de 0 à 3 fichiers `images[]` — JPEG, PNG ou WebP, 5 Mo maximum chacun. "
                    . "Le JSON simple (`application/json`) reste accepté, sans image. "
                    . "Tout ou rien : une image refusée (`400`) ou un stockage indisponible (`503`) "
                    . "n'enregistre aucun événement.\n\n"
                    . "Réponse `201` : enveloppe `{ message, status, data }` ; `data.images` liste "
                    . "`{ key, url }`, où `url` est une URL présignée à durée limitée.",
                requestBody: new \ApiPlatform\OpenApi\Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'properties' => [
                                    'data' => [
                                        'type' => 'string',
                                        'description' => "L'événement en JSON (même format que le corps application/json).",
                                        'example' => '{"title":"Concert","description":"…","startedAt":"2027-01-01T18:00:00+03:00","endAt":"2027-01-01T22:00:00+03:00","organizer":{"id":1},"category":{"id":1},"location":{"id":1},"ticket_type":[{"name":"Standard","prix":10000,"quantite_max":100}]}',
                                    ],
                                    'images[]' => [
                                        'type' => 'array',
                                        'maxItems' => self::MAX_IMAGES,
                                        'items' => ['type' => 'string', 'format' => 'binary'],
                                        'description' => '0 à 3 images (JPEG, PNG, WebP ; 5 Mo max chacune).',
                                    ],
                                ],
                            ],
                            'encoding' => ['images[]' => ['contentType' => 'image/jpeg, image/png, image/webp']],
                        ],
                        // Schéma écrit à la main : avec `deserialize: false`, API Platform
                        // ne le génère pas. Champs lus par EventInputDenormalizer.
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['title', 'description', 'startedAt', 'endAt', 'organizer', 'location'],
                                'properties' => [
                                    'title' => ['type' => 'string', 'maxLength' => 150],
                                    'description' => ['type' => 'string'],
                                    'startedAt' => ['type' => 'string', 'format' => 'date-time'],
                                    'endAt' => ['type' => 'string', 'format' => 'date-time'],
                                    'organizer' => [
                                        'type' => 'object', 'required' => ['id'],
                                        'properties' => ['id' => ['type' => 'integer']],
                                    ],
                                    'category' => [
                                        'type' => 'object',
                                        'description' => 'Par `id`, ou créée à partir de `name` / `color`.',
                                        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'color' => ['type' => 'string']],
                                    ],
                                    'location' => [
                                        'type' => 'object',
                                        'description' => 'Par `id`, ou créé à partir de `name` / `size`.',
                                        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'size' => ['type' => 'integer']],
                                    ],
                                    'ticket_type' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['name', 'prix', 'quantite_max'],
                                            'properties' => ['name' => ['type' => 'string'], 'prix' => ['type' => 'integer'], 'quantite_max' => ['type' => 'integer']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
    ]
)]
class Event
{
    /** Nombre maximal d'images par événement (zéro est accepté). */
    public const MAX_IMAGES = 3;

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

    /**
     * Clés des images dans le bucket S3 (ex. `events/42/<uuid>-affiche.jpg`),
     * jamais des URLs : le bucket est privé, les URLs présignées sont générées
     * à la sérialisation ({@see \App\Serializer\Normalizer\EventImagesNormalizer}).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $images = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    #[Groups(['events:lists'])]
    private ?bool $status = null;

    #[ORM\ManyToOne(inversedBy: 'events', cascade: ['persist'])]
    #[Groups(['events:lists', 'events:details', 'events:create'])]
    private ?Category $category = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
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

    /** @return list<string> */
    public function getImages(): array
    {
        return $this->images;
    }

    /** @param list<string> $images */
    public function setImages(array $images): static
    {
        if (count($images) > self::MAX_IMAGES) {
            throw new \LengthException(sprintf('Un événement compte au plus %d images.', self::MAX_IMAGES));
        }

        $this->images = array_values($images);

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
}
