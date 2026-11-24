<?php

namespace App\Controller\Api\Organizer;

use App\Exception\OrganizerException;
use App\Repository\EventRepository;
use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipRequestResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Événements d'**une** organisation (`GET /api/events/me`, lui, agrège toutes
 * celles de l'utilisateur). Droit requis : `ORGANIZER_VIEW`.
 *
 * Même réponse que `/api/events` (champs `events:lists`, enveloppe, pagination).
 * Brouillons compris : c'est une vue de gestion.
 *
 * Filtres facultatifs, combinables :
 * - `status=published|draft` ;
 * - `period=upcoming|ongoing|past` (par rapport à l'heure courante).
 */
#[AsController]
#[Route('/api/organizers/{organizerId}/events', name: 'api_organizer_events', methods: ['GET'], requirements: ['organizerId' => '\d+'])]
final class GetOrganizerEventsController extends AbstractController
{
    private const ITEMS_PER_PAGE_DEFAULT = 10;
    private const ITEMS_PER_PAGE_MAX = 20;
    private const STATUSES = ['published', 'draft'];
    private const PERIODS = [EventRepository::PERIOD_UPCOMING, EventRepository::PERIOD_ONGOING, EventRepository::PERIOD_PAST];

    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly EventRepository $eventRepository,
        private readonly SerializerInterface $serializer,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::VIEW, $organizer);

        $status = $this->choice($request, 'status', self::STATUSES);
        $period = $this->choice($request, 'period', self::PERIODS);
        $published = $status === null ? null : $status === 'published';

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(
            self::ITEMS_PER_PAGE_MAX,
            max(1, (int) $request->query->get('itemsPerPage', self::ITEMS_PER_PAGE_DEFAULT))
        );

        $itemsTotal = $this->eventRepository->countByOrganizer($organizer, $published, $period);
        $events = $this->eventRepository->findByOrganizer($organizer, $published, $period, $itemsPerPage, ($page - 1) * $itemsPerPage);

        return $this->json([
            'message' => 'Événements de l’organisation récupérés avec succès',
            'status' => 200,
            'data' => [
                'organizer' => ['id' => $organizer->getId(), 'name' => $organizer->getName()],
                'itemsTotal' => $itemsTotal,
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => json_decode(
                    $this->serializer->serialize($events, 'json', ['groups' => ['events:lists']]),
                    true
                ),
            ],
        ], 200);
    }

    /** @param string[] $accepted */
    private function choice(Request $request, string $name, array $accepted): ?string
    {
        $query = $request->query->all();
        if (!array_key_exists($name, $query)) {
            return null;
        }

        $value = $query[$name];
        if (!is_string($value) || !in_array($value, $accepted, true)) {
            throw OrganizerException::filtreInvalide($name, $accepted);
        }

        return $value;
    }
}
