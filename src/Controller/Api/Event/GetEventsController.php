<?php

namespace App\Controller\Api\Event;

use App\Entity\Event;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
class GetEventsController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private SerializerInterface $serializer
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Récupérer les paramètres de pagination
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(20, max(1, (int) $request->query->get('itemsPerPage', 10)));

        // Calculer l'offset
        $offset = ($page - 1) * $itemsPerPage;

        // Récupérer le nombre total d'événements
        $totalItems = $this->eventRepository->count([]);

        // Récupérer les événements paginés
        $events = $this->eventRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            $itemsPerPage,
            $offset
        );

        // Sérialiser les événements
        $serializedEvents = json_decode(
            $this->serializer->serialize(
                $events,
                'json',
                ['groups' => ['events:lists']]
            ),
            true
        );

        // Formater la réponse selon le format demandé
        $response = [
            'message' => 'Liste des événements récupérée avec succès',
            'status' => 200,
            'data' => [
                'itemsTotal' => $totalItems,
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => $serializedEvents
            ]
        ];

        return $this->json($response, 200);
    }
}
