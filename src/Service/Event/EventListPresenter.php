<?php

namespace App\Service\Event;

use App\Entity\User;
use App\Repository\EventRepository;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Page d'événements au format commun des trois listes (`events:lists`, avec
 * `itemsTotal` calculé **avec** les filtres, sans quoi la pagination serait fausse).
 */
final class EventListPresenter
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly SerializerInterface $serializer,
    ) {}

    /** @return array{itemsTotal: int, currentPage: int, nombreParPage: int, items: array} */
    public function page(EventFilters $filters, ?User $scope, int $page, int $itemsPerPage): array
    {
        $events = $this->events->findFiltered($filters, $scope, $itemsPerPage, ($page - 1) * $itemsPerPage);

        return [
            'itemsTotal' => $this->events->countFiltered($filters, $scope),
            'currentPage' => $page,
            'nombreParPage' => $itemsPerPage,
            'items' => json_decode($this->serializer->serialize($events, 'json', ['groups' => ['events:lists']]), true),
        ];
    }
}
