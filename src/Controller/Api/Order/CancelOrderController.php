<?php

namespace App\Controller\Api\Order;

use App\Entity\User;
use App\Exception\OrderException;
use App\Repository\TicketOrderRepository;
use App\Service\Order\OrderPresenter;
use App\Service\Order\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annulation par l'acheteur d'une commande en attente de paiement : les
 * places sont rendues. Une commande payée relève du remboursement (`409`).
 */
#[AsController]
#[Route('/api/orders/{reference}/cancel', name: 'api_order_cancel', methods: ['POST'], requirements: ['reference' => 'TU-[A-Z0-9]+'])]
final class CancelOrderController extends AbstractController
{
    public function __construct(
        private readonly TicketOrderRepository $orders,
        private readonly OrderService $orderService,
        private readonly OrderPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(string $reference): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $order = $this->orders->findOneByReference($reference)
            ?? throw OrderException::commandeIntrouvable($reference);

        $order = $this->orderService->cancel($order, $user);

        return $this->json([
            'message' => 'Commande annulée. Les places ont été remises en vente.',
            'status' => 200,
            'data' => $this->presenter->order($order),
        ], 200);
    }
}
