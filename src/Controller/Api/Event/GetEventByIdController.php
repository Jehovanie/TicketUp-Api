<?php

namespace App\Controller\Api\Event;

use App\Entity\Event;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
class GetEventByIdController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private SerializerInterface $serializer
    ) {}

    public function __invoke(int $id): JsonResponse
    {
        // Récupérer l'événement par son ID
        $event = $this->eventRepository->find($id);

        // Si l'événement n'existe pas
        if (!$event) {
            return $this->json([
                'message' => 'Événement non trouvé',
                'status' => 404,
                'data' => null
            ], 404);
        }

        // Sérialiser l'événement
        $serializedEvent = json_decode(
            $this->serializer->serialize(
                $event,
                'json',
                ['groups' => ['events:lists', 'events:details']]
            ),
            true
        );

        // Formater la réponse
        $response = [
            'message' => 'Événement récupéré avec succès',
            'status' => 200,
            'data' => $serializedEvent
        ];

        return $this->json($response, 200);
    }
}
