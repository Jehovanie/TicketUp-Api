<?php

namespace App\Tests\Support;

use App\Entity\Event;
use App\Entity\TicketOrder;
use App\Entity\TicketType;
use App\Entity\User;
use App\Exception\OrderException;
use App\Service\Order\OrderService;

/**
 * Outils communs aux tests d'intégration de la commande de billets.
 */
abstract class OrderTestCase extends DatabaseTestCase
{
    protected function orders(): OrderService
    {
        return static::getContainer()->get(OrderService::class);
    }

    /** Une ligne de commande telle que la reçoit `OrderService::place()`. */
    protected static function line(TicketType $ticketType, int $quantity): array
    {
        return ['ticketTypeId' => (int) $ticketType->getId(), 'quantity' => $quantity];
    }

    /** Raccourci : une commande d'un seul tarif. */
    protected function placeOrder(User $buyer, Event $event, TicketType $ticketType, int $quantity = 1, ?string $idempotencyKey = null): TicketOrder
    {
        return $this->orders()->place($buyer, (int) $event->getId(), [self::line($ticketType, $quantity)], $idempotencyKey);
    }

    /** Places réservées, lues en SQL (jamais depuis l'entité en mémoire). */
    protected function reserved(TicketType $ticketType): int
    {
        return $this->fetchInt('SELECT quantite_reservee FROM ticket_type WHERE id = ?', [$ticketType->getId()]);
    }

    protected function orderStatus(TicketOrder|int $order): string
    {
        $id = $order instanceof TicketOrder ? $order->getId() : $order;

        return (string) $this->fetchValue('SELECT status FROM ticket_order WHERE id = ?', [$id]);
    }

    protected function countOrders(): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM ticket_order');
    }

    protected function countTicketsOf(TicketOrder $order): int
    {
        return $this->fetchInt(
            'SELECT COUNT(*) FROM ticket t JOIN order_item i ON i.id = t.order_item_id WHERE i.order_id = ?',
            [$order->getId()]
        );
    }

    /**
     * Exécute `$action` et exige une `OrderException` portant ce code HTTP.
     */
    protected function assertOrderError(int $expectedStatus, callable $action): OrderException
    {
        try {
            $action();
        } catch (OrderException $exception) {
            self::assertSame(
                $expectedStatus,
                $exception->getStatusCode(),
                sprintf('Code inattendu pour « %s »', $exception->getMessage())
            );

            return $exception;
        }

        self::fail(sprintf('Une OrderException (HTTP %d) était attendue.', $expectedStatus));
    }
}
