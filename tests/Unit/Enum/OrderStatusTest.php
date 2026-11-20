<?php

namespace App\Tests\Unit\Enum;

use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statuts de commande et de billet (docs/database/design-orders.md § 4).
 * Les valeurs sont stockées en base et contrôlées par des CHECK SQL : elles
 * ne doivent pas changer sans migration.
 */
final class OrderStatusTest extends TestCase
{
    public function testValeursStockeesEnBase(): void
    {
        self::assertSame(
            ['pending', 'paid', 'cancelled', 'expired', 'refunded'],
            array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases())
        );
    }

    public static function seatsProvider(): iterable
    {
        yield 'en attente : places bloquées' => [OrderStatus::PENDING, true];
        yield 'payée : places vendues' => [OrderStatus::PAID, true];
        yield 'annulée : places rendues' => [OrderStatus::CANCELLED, false];
        yield 'expirée : places rendues' => [OrderStatus::EXPIRED, false];
        yield 'remboursée : places rendues' => [OrderStatus::REFUNDED, false];
    }

    #[DataProvider('seatsProvider')]
    public function testOccupationDesPlaces(OrderStatus $status, bool $holdsSeats): void
    {
        self::assertSame($holdsSeats, $status->holdsSeats());
    }

    public static function finalProvider(): iterable
    {
        yield [OrderStatus::PENDING, false];
        yield [OrderStatus::PAID, false];
        yield [OrderStatus::CANCELLED, true];
        yield [OrderStatus::EXPIRED, true];
        yield [OrderStatus::REFUNDED, true];
    }

    #[DataProvider('finalProvider')]
    public function testStatutsTerminaux(OrderStatus $status, bool $isFinal): void
    {
        self::assertSame($isFinal, $status->isFinal());
    }

    public function testChaqueStatutAUnLibelleDistinct(): void
    {
        $labels = array_map(static fn (OrderStatus $s) => $s->label(), OrderStatus::cases());

        self::assertNotContains('', $labels);
        self::assertCount(count($labels), array_unique($labels));
    }

    public function testValeursDesStatutsDeBillet(): void
    {
        self::assertSame(
            ['valid', 'used', 'cancelled'],
            array_map(static fn (TicketStatus $s) => $s->value, TicketStatus::cases())
        );
    }
}
