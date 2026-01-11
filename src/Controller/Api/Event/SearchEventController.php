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
class SearchEventController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private SerializerInterface $serializer
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $categoryId = $request->query->get('category');
        $title = $request->query->get('title');
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(20, max(1, (int) $request->query->get('itemsPerPage', 10)));

        $criteria = [];

        if ($categoryId) {
            $criteria['categoryId'] = (int) $categoryId;
        }

        if ($title) {
            $criteria['title'] = $title;
        }

        if ($startDate) {
            try {
                $criteria['startDate'] = new \DateTimeImmutable($startDate);
            } catch (\Exception $e) {
                return $this->json([
                    'message' => 'Format de date invalide pour startDate. Utilisez le format: Y-m-d',
                    'status' => 400,
                    'data' => null
                ], 400);
            }
        }

        if ($endDate) {
            try {
                $criteria['endDate'] = new \DateTimeImmutable($endDate);
            } catch (\Exception $e) {
                return $this->json([
                    'message' => 'Format de date invalide pour endDate. Utilisez le format: Y-m-d',
                    'status' => 400,
                    'data' => null
                ], 400);
            }
        }

        // Si aucun critère n'est fourni, retourner une erreur
        if (empty($criteria)) {
            return $this->json([
                'message' => 'Au moins un critère de recherche est requis (category, title, startDate, endDate)',
                'status' => 400,
                'data' => null
            ], 400);
        }

        $events = $this->eventRepository->searchEvents($criteria);
        
        // Calculer la pagination
        $totalItems = count($events);
        $offset = ($page - 1) * $itemsPerPage;
        $paginatedEvents = array_slice($events, $offset, $itemsPerPage);

        // Sérialiser les résultats avec les groupes appropriés
        $serializedEvents = json_decode(
            $this->serializer->serialize(
                $paginatedEvents,
                'json',
                ['groups' => ['events:lists']]
            ),
            true
        );

        // Formater la réponse
        $response = [
            'message' => 'Recherche effectuée avec succès',
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
