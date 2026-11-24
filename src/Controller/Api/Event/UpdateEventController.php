<?php

namespace App\Controller\Api\Event;

use App\Exception\EventException;
use App\Repository\EventRepository;
use App\Security\Voter\OrganizerVoter;
use App\Service\Event\EventUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Modification partielle d'un événement : `PATCH /api/events/{id}`.
 *
 * Droits, sur l'organisation **de l'événement** :
 * - modifier : `ORGANIZER_VIEW` — **tout membre** (membre, administrateur, responsable) ;
 * - toucher à `status` (publier) : `ORGANIZER_EDIT` — administrateur ou responsable
 *   seulement. Un membre qui envoie la clé `status`, même avec une valeur sans
 *   effet, reçoit `403` et rien n'est écrit.
 *
 * Champs et règles : {@see EventUpdater}. Publier par `PATCH` applique les mêmes
 * règles que `POST /api/events/{id}/publish`.
 */
#[AsController]
#[Route('/api/events/{id}', name: 'api_event_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
final class UpdateEventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventUpdater $updater,
        private readonly SerializerInterface $serializer,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $event = $this->events->find($id) ?? throw EventException::introuvable($id);
        $organizer = $event->getOrganizer();

        $this->denyAccessUnlessGranted(OrganizerVoter::VIEW, $organizer);

        $payload = json_decode($request->getContent() ?: '[]', true);
        $payload = is_array($payload) ? $payload : [];

        if (EventUpdater::touchesPublication($payload) && !$this->isGranted(OrganizerVoter::EDIT, $organizer)) {
            throw EventException::publicationReservee();
        }

        $this->updater->update($event, $payload);

        return $this->json([
            'message' => sprintf('« %s » a été mis à jour.', $event->getTitle()),
            'status' => 200,
            'data' => json_decode(
                $this->serializer->serialize($event, 'json', ['groups' => ['events:lists', 'events:details']]),
                true
            ),
        ], 200);
    }
}
