<?php

namespace App\Controller\Api\Order;

use App\Exception\OrderException;
use App\Repository\TicketOrderRepository;
use App\Security\Voter\OrderVoter;
use App\Service\Order\OrderPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Détail d'une commande, avec ses billets.
 *
 * Visible par l'acheteur, les membres de l'organisation de l'événement et le
 * super administrateur (`OrderVoter`). Pour les autres : `404`, comme si la
 * référence n'existait pas.
 */
#[AsController]
#[Route('/api/orders/{reference}', name: 'api_order_show', methods: ['GET'], requirements: ['reference' => 'TU-[A-Z0-9]+'])]
final class GetOrderController extends AbstractController
{
    public function __construct(
        private readonly TicketOrderRepository $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(string $reference): JsonResponse
    {
        $order = $this->orders->findOneByReference($reference);

        if ($order === null || !$this->isGranted(OrderVoter::VIEW, $order)) {
            throw OrderException::commandeIntrouvable($reference);
        }

        // L'organisateur a besoin de savoir qui a commandé ; l'acheteur le sait.
        $withBuyer = $order->getUser()?->getId() !== $this->getUser()?->getId();

        return $this->json([
            'message' => 'Commande récupérée avec succès',
            'status' => 200,
            'data' => $this->presenter->order($order, $withBuyer),
        ], 200);
    }
}
