<?php

namespace App\Tests\Functional\Event;

use App\Service\Order\OrderService;
use App\Tests\Support\ApiTestCase;

/**
 * Le catalogue affiche les places restantes par tarif (design-orders.md § 6) :
 * `quantite_disponible = quantite_max - quantite_reservee`, au même format
 * snake_case que `quantite_max`. Le compteur interne n'est pas exposé.
 */
final class TicketAvailabilityApiTest extends ApiTestCase
{
    public function testLaListeDesEvenementsAfficheLesPlacesRestantes(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event, 'VIP', 50000, 10);
        static::getContainer()->get(OrderService::class)->place(
            $this->data->user(),
            $event->getId(),
            [['ticketTypeId' => $ticketType->getId(), 'quantity' => 3]]
        );

        $body = $this->assertEnvelope($this->request('GET', '/api/events'), 200);

        $item = $body['data']['items'][0];
        self::assertSame($event->getId(), $item['id']);

        $tarif = $item['ticket_type'][0];
        self::assertSame(10, $tarif['quantite_max']);
        self::assertSame(7, $tarif['quantite_disponible']);
        self::assertArrayNotHasKey('quantite_reservee', $tarif);
    }
}
