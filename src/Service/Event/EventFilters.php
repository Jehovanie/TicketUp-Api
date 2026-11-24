<?php

namespace App\Service\Event;

/**
 * Critères facultatifs et combinables d'une liste d'événements. `null` = pas de
 * filtre (« tous »). Immuable : les contrôleurs imposent leurs propres bornes
 * (organisation fixée, publiés seulement) avec les `with*()`.
 */
final class EventFilters
{
    public const PERIOD_UPCOMING = 'upcoming';
    public const PERIOD_ONGOING = 'ongoing';
    public const PERIOD_PAST = 'past';

    public const DEFAULT_SORT = 'createdAt:desc';

    /** Tris acceptés → [champ, sens]. */
    public const SORTS = [
        'createdAt:desc' => ['createdAt', 'DESC'],
        'startedAt:asc' => ['startedAt', 'ASC'],
        'startedAt:desc' => ['startedAt', 'DESC'],
        'title:asc' => ['title', 'ASC'],
    ];

    public function __construct(
        public readonly ?string $title = null,
        public readonly ?int $organizerId = null,
        public readonly ?int $categoryId = null,
        /** `true` = publiés, `false` = brouillons. */
        public readonly ?bool $published = null,
        public readonly ?string $period = null,
        /** Début de journée, inclus. */
        public readonly ?\DateTimeImmutable $startDate = null,
        /** Dernier jour demandé, inclus en entier (jusqu'à 23:59:59). */
        public readonly ?\DateTimeImmutable $endDate = null,
        public readonly string $sort = self::DEFAULT_SORT,
    ) {}

    public function withOrganizer(?int $organizerId): self
    {
        return new self($this->title, $organizerId, $this->categoryId, $this->published, $this->period, $this->startDate, $this->endDate, $this->sort);
    }

    public function withPublished(?bool $published): self
    {
        return new self($this->title, $this->organizerId, $this->categoryId, $published, $this->period, $this->startDate, $this->endDate, $this->sort);
    }
}
