<?php

namespace App\Controller\Api\Order;

use App\Entity\User;
use App\Exception\OrderException;
use App\Repository\TicketOrderRepository;
use App\Service\Order\OrderPresenter;
use App\Service\Order\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Envoi d'une commande de billets.
 *
 * Corps : `{ "eventId": 12, "items": [ { "ticketTypeId": 31, "quantity": 2 } ] }`.
 * Les prix éventuellement envoyés sont ignorés : le total est calculé à partir
 * des tarifs en base.
 *
 * En-tête facultatif `Idempotency-Key` : un renvoi avec la même clé (réseau
 * mobile instable) renvoie la commande déjà créée, en `200` au lieu de `201`.
 */
#[AsController]
#[Route('/api/orders', name: 'api_order_place', methods: ['POST'])]
final class PlaceOrderController extends AbstractController
{
    private const IDEMPOTENCY_KEY_MAX = 64;

    public function __construct(
        private readonly OrderService $orderService,
        private readonly TicketOrderRepository $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw OrderException::corpsInvalide();
        }

        $eventId = $payload['eventId'] ?? null;
        if (!is_int($eventId)) {
            throw OrderException::champInvalide('eventId', 'un entier');
        }

        $lines = $this->lines($payload['items'] ?? null);
        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey !== null) {
            $existing = $this->orders->findOneByUserAndIdempotencyKey($user, $idempotencyKey);
            if ($existing !== null) {
                return $this->respond($existing, 'Cette commande a déjà été enregistrée.', 200);
            }
        }

        $order = $this->orderService->place($user, $eventId, $lines, $idempotencyKey);

        return $this->respond(
            $order,
            $order->getTotalAmount() === 0
                ? 'Commande enregistrée. Vos billets sont disponibles.'
                : sprintf('Commande enregistrée. Vos places sont réservées pendant %d minutes, le temps du paiement.', OrderService::RESERVATION_MINUTES),
            201
        );
    }

    /** @return list<array{ticketTypeId: int, quantity: int}> */
    private function lines(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw OrderException::champInvalide('items', 'une liste de tarifs');
        }

        $lines = [];
        foreach ($items as $position => $item) {
            if (!is_array($item) || !is_int($item['ticketTypeId'] ?? null)) {
                throw OrderException::champInvalide(sprintf('items[%d].ticketTypeId', $position), 'un entier');
            }

            if (!is_int($item['quantity'] ?? null)) {
                throw OrderException::champInvalide(sprintf('items[%d].quantity', $position), 'un entier');
            }

            $lines[] = ['ticketTypeId' => $item['ticketTypeId'], 'quantity' => $item['quantity']];
        }

        return $lines;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));

        if ($key === '') {
            return null;
        }

        if (strlen($key) > self::IDEMPOTENCY_KEY_MAX) {
            throw OrderException::cleIdempotenceInvalide(self::IDEMPOTENCY_KEY_MAX);
        }

        return $key;
    }

    private function respond(\App\Entity\TicketOrder $order, string $message, int $status): JsonResponse
    {
        return $this->json([
            'message' => $message,
            'status' => $status,
            'data' => $this->presenter->order($order),
        ], $status);
    }
}
