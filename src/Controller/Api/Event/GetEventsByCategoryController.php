<?php

namespace App\Controller\Api\Event;

use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
class GetEventsByCategoryController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private SerializerInterface $serializer
    ) {}

    public function __invoke(int $categoryId): JsonResponse
    {
        $events = $this->eventRepository->findByCategory($categoryId);

        // Sérialiser les résultats avec les groupes appropriés
        $jsonContent = $this->serializer->serialize(
            $events,
            'json',
            ['groups' => ['events:lists']]
        );

        return new JsonResponse($jsonContent, 200, [], true);
    }
}
