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
                    'error' => 'Format de date invalide pour startDate. Utilisez le format: Y-m-d'
                ], 400);
            }
        }

        if ($endDate) {
            try {
                $criteria['endDate'] = new \DateTimeImmutable($endDate);
            } catch (\Exception $e) {
                return $this->json([
                    'error' => 'Format de date invalide pour endDate. Utilisez le format: Y-m-d'
                ], 400);
            }
        }

        // Si aucun critère n'est fourni, retourner une erreur
        if (empty($criteria)) {
            return $this->json([
                'error' => 'Au moins un critère de recherche est requis (category, title, startDate, endDate)'
            ], 400);
        }

        $events = $this->eventRepository->searchEvents($criteria);

        // Sérialiser les résultats avec les groupes appropriés
        $jsonContent = $this->serializer->serialize(
            $events,
            'json',
            ['groups' => ['events:lists']]
        );

        return new JsonResponse($jsonContent, 200, [], true);
    }
}
