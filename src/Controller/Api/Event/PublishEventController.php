<?php

namespace App\Controller\Api\Event;

use App\Exception\EventException;
use App\Repository\EventRepository;
use App\Security\Voter\OrganizerVoter;
use App\Service\Event\EventPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Publie un événement : `POST /api/events/{id}/publish`, sans corps.
 *
 * Droit requis : `ORGANIZER_EDIT` sur l'organisation **de l'événement**
 * (administrateur ou plus, ou `ROLE_SUPER_ADMIN`). Le contrôle est fait ici :
 * masquer le bouton côté interface n'est qu'un confort.
 *
 * Idempotent (200 si déjà publié). Règles de publication : {@see EventPublisher}.
 */
#[AsController]
#[Route('/api/events/{id}/publish', name: 'api_event_publish', methods: ['POST'], requirements: ['id' => '\d+'])]
final class PublishEventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventPublisher $publisher,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $id): JsonResponse
    {
        $event = $this->events->find($id) ?? throw EventException::introuvable($id);
        $this->denyAccessUnlessGranted(OrganizerVoter::EDIT, $event->getOrganizer());

        $published = $this->publisher->publish($event);

        return $this->json([
            'message' => $published
                ? sprintf('« %s » est maintenant publié.', $event->getTitle())
                : sprintf('« %s » était déjà publié.', $event->getTitle()),
            'status' => 200,
            'data' => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'published' => true,
                'alreadyPublished' => !$published,
            ],
        ], 200);
    }
}
