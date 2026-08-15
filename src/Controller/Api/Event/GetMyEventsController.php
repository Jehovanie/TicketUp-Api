<?php

namespace App\Controller\Api\Event;

use App\Entity\User;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Événements de l'utilisateur connecté — `/api/events` restreint à ce qui le concerne.
 *
 * Même réponse que `/api/events` (mêmes champs, même enveloppe, même pagination),
 * seul l'ensemble listé change : les événements portés par les organisations dont
 * la personne est membre. Le rattachement passe par `OrganizerMembership`, un
 * événement n'ayant pas d'auteur propre — il appartient à une organisation.
 *
 * Comme pour `/api/organizers/me`, `ROLE_SUPER_ADMIN` ne donne pas d'appartenance :
 * un super administrateur membre d'aucune organisation reçoit une liste vide.
 * L'inventaire complet reste `/api/events`.
 */
#[AsController]
final class GetMyEventsController extends AbstractController
{
    private const ITEMS_PER_PAGE_DEFAULT = 10;
    private const ITEMS_PER_PAGE_MAX = 20;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly SerializerInterface $serializer,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(
            self::ITEMS_PER_PAGE_MAX,
            max(1, (int) $request->query->get('itemsPerPage', self::ITEMS_PER_PAGE_DEFAULT))
        );

        $itemsTotal = $this->eventRepository->countByUser($user);
        $events = $this->eventRepository->findByUser(
            $user,
            $itemsPerPage,
            ($page - 1) * $itemsPerPage
        );

        $serializedEvents = json_decode(
            $this->serializer->serialize($events, 'json', ['groups' => ['events:lists']]),
            true
        );

        return $this->json([
            'message' => 'Liste de vos événements récupérée avec succès',
            'status' => 200,
            'data' => [
                'itemsTotal' => $itemsTotal,
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => $serializedEvents,
            ],
        ], 200);
    }
}
