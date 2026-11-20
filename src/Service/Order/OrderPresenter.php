<?php

namespace App\Service\Order;

use App\Entity\TicketOrder;

/**
 * Mise en forme JSON d'une commande.
 *
 * L'identifiant séquentiel n'est jamais exposé : la commande se désigne par sa
 * `reference`. Les coordonnées de l'acheteur ne figurent que dans la vue
 * organisateur (`withBuyer`), l'acheteur les connaissant déjà.
 */
final class OrderPresenter
{
    public function order(TicketOrder $order, bool $withBuyer = false): array
    {
        $event = $order->getEvent();

        $data = [
            'reference' => $order->getReference(),
            'status' => $order->getStatus()->value,
            'statusLabel' => $order->getStatus()->label(),
            'totalAmount' => $order->getTotalAmount(),
            'currency' => $order->getCurrency(),
            'ticketCount' => $order->getTicketCount(),
            'expiresAt' => self::date($order->getExpiresAt()),
            'paidAt' => self::date($order->getPaidAt()),
            'cancelledAt' => self::date($order->getCancelledAt()),
            'createdAt' => self::date($order->getCreatedAt()),
            'event' => [
                'id' => $event?->getId(),
                'title' => $event?->getTitle(),
                'startedAt' => self::date($event?->getStartedAt()),
                'endAt' => self::date($event?->getEndAt()),
            ],
            'items' => [],
            'tickets' => [],
        ];

        foreach ($order->getItems() as $item) {
            $data['items'][] = [
                'ticketTypeId' => $item->getTicketType()?->getId(),
                'name' => $item->getTicketTypeName(),
                'unitPrice' => $item->getUnitPrice(),
                'quantity' => $item->getQuantity(),
                'lineTotal' => $item->getLineTotal(),
            ];

            foreach ($item->getTickets() as $ticket) {
                $data['tickets'][] = [
                    'code' => $ticket->getCode(),
                    'status' => $ticket->getStatus()->value,
                    'ticketTypeName' => $item->getTicketTypeName(),
                    'holderName' => $ticket->getHolderName(),
                ];
            }
        }

        if ($withBuyer) {
            $data['buyer'] = [
                'email' => $order->getBuyerEmail(),
                'firstname' => $order->getBuyerFirstname(),
                'lastname' => $order->getBuyerLastname(),
                'phone' => $order->getBuyerPhone(),
            ];
        }

        return $data;
    }

    /**
     * @param TicketOrder[] $orders
     */
    public function orders(array $orders, bool $withBuyer = false): array
    {
        return array_map(fn (TicketOrder $order) => $this->order($order, $withBuyer), $orders);
    }

    private static function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
