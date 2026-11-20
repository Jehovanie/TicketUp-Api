<?php

namespace App\Controller\Api\Order;

use App\Exception\OrderException;
use App\Repository\EventRepository;
use App\Repository\TicketOrderRepository;
use App\Security\Voter\OrganizerVoter;
use App\Service\Order\OrderPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commandes d'un événement, pour l'organisation qui le porte (droit VIEW de
 * `OrganizerVoter` : tout membre). Brouillons compris : c'est une vue de gestion.
 */
#[AsController]
#[Route('/api/events/{eventId}/orders', name: 'api_event_orders', methods: ['GET'], requirements: ['eventId' => '\d+'])]
final class GetEventOrdersController extends AbstractController
{
    private const ITEMS_PER_PAGE_DEFAULT = 10;
    private const ITEMS_PER_PAGE_MAX = 20;

    public function __construct(
        private readonly EventRepository $events,
        private readonly TicketOrderRepository $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $eventId, Request $request): JsonResponse
    {
        $event = $this->events->find($eventId) ?? throw OrderException::evenementIntrouvable($eventId);

        if (!$this->isGranted(OrganizerVoter::VIEW, $event->getOrganizer())) {
            throw OrderException::accesRefuse();
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(
            self::ITEMS_PER_PAGE_MAX,
            max(1, (int) $request->query->get('itemsPerPage', self::ITEMS_PER_PAGE_DEFAULT))
        );

        return $this->json([
            'message' => 'Commandes de l’événement récupérées avec succès',
            'status' => 200,
            'data' => [
                'event' => ['id' => $event->getId(), 'title' => $event->getTitle()],
                'itemsTotal' => $this->orders->countByEvent($event),
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => $this->presenter->orders(
                    $this->orders->findByEvent($event, $itemsPerPage, ($page - 1) * $itemsPerPage),
                    withBuyer: true
                ),
            ],
        ], 200);
    }
}
