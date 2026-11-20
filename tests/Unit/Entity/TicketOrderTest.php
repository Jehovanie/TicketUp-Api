<?php

namespace App\Tests\Unit\Entity;

use App\Entity\OrderItem;
use App\Entity\TicketOrder;
use App\Entity\TicketType;
use App\Enum\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * Règles portées par les entités elles-mêmes, sans base de données.
 */
final class TicketOrderTest extends TestCase
{
    private static function ticketType(string $name, int $prix, int $quantiteMax = 100): TicketType
    {
        return (new TicketType())->setName($name)->setPrix($prix)->setQuantiteMax($quantiteMax);
    }

    public function testUneNouvelleCommandeEstEnAttenteEnAriary(): void
    {
        $order = new TicketOrder();

        self::assertSame(OrderStatus::PENDING, $order->getStatus());
        self::assertSame('MGA', $order->getCurrency());
        self::assertSame(0, $order->getTotalAmount());
        self::assertCount(0, $order->getItems());
    }

    public function testUneLigneFigeLeNomEtLePrixDuTarif(): void
    {
        $vip = self::ticketType('VIP', 50000);

        $item = OrderItem::fromTicketType($vip, 3);

        self::assertSame($vip, $item->getTicketType());
        self::assertSame('VIP', $item->getTicketTypeName());
        self::assertSame(50000, $item->getUnitPrice());
        self::assertSame(3, $item->getQuantity());
        self::assertSame(150000, $item->getLineTotal());

        // Le tarif change après l'achat : la ligne ne bouge pas.
        $vip->setPrix(99000)->setName('VIP Gold');

        self::assertSame(50000, $item->getUnitPrice());
        self::assertSame('VIP', $item->getTicketTypeName());
        self::assertSame(150000, $item->getLineTotal());
    }

    public function testLeTotalEstLaSommeDesLignes(): void
    {
        $order = new TicketOrder();
        $order->addItem(OrderItem::fromTicketType(self::ticketType('Entrée générale', 20000), 2));
        $order->addItem(OrderItem::fromTicketType(self::ticketType('VIP', 50000), 1));

        self::assertSame(90000, $order->getTotalAmount());
        self::assertSame(3, $order->getTicketCount());
        self::assertCount(2, $order->getItems());

        foreach ($order->getItems() as $item) {
            self::assertSame($order, $item->getOrder());
        }
    }

    public function testUneCommandeDeTarifsGratuitsVautZero(): void
    {
        $order = new TicketOrder();
        $order->addItem(OrderItem::fromTicketType(self::ticketType('Zone Presse', 0), 2));

        self::assertSame(0, $order->getTotalAmount());
        self::assertSame(2, $order->getTicketCount());
    }

    public function testUnNouveauTarifNaAucunePlaceReservee(): void
    {
        $ticketType = self::ticketType('Entrée générale', 20000, 150);

        self::assertSame(0, $ticketType->getQuantiteReservee());
        self::assertSame(150, $ticketType->getQuantiteDisponible());
    }
}
