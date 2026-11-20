<?php

namespace App\Controller\Api\Order;

use App\Entity\User;
use App\Repository\TicketOrderRepository;
use App\Service\Order\OrderPresenter;
use App\Service\Order\OrderStatusFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commandes de l'utilisateur connecté, de la plus récente à la plus ancienne.
 * Pagination identique à `/api/events` (`page`, `itemsPerPage` ≤ 20).
 *
 * Filtre facultatif `status` (une valeur ou plusieurs séparées par des
 * virgules) ; `countsByStatus` donne le total par statut, sans tenir compte
 * du filtre, pour les compteurs des onglets du front.
 */
#[AsController]
#[Route('/api/orders/me', name: 'api_order_mine', methods: ['GET'], priority: 10)]
final class GetMyOrdersController extends AbstractController
{
    private const ITEMS_PER_PAGE_DEFAULT = 10;
    private const ITEMS_PER_PAGE_MAX = 20;

    public function __construct(
        private readonly TicketOrderRepository $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $statuses = OrderStatusFilter::fromRequest($request);

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(
            self::ITEMS_PER_PAGE_MAX,
            max(1, (int) $request->query->get('itemsPerPage', self::ITEMS_PER_PAGE_DEFAULT))
        );

        return $this->json([
            'message' => 'Liste de vos commandes récupérée avec succès',
            'status' => 200,
            'data' => [
                'itemsTotal' => $this->orders->countByUser($user, $statuses),
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => $this->presenter->orders(
                    $this->orders->findByUser($user, $itemsPerPage, ($page - 1) * $itemsPerPage, $statuses)
                ),
                'countsByStatus' => $this->orders->countsByStatusForUser($user),
            ],
        ], 200);
    }
}
