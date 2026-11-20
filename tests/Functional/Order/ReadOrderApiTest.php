<?php

namespace App\Tests\Functional\Order;

use App\Entity\Event;
use App\Entity\Organizer;
use App\Entity\TicketOrder;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Service\Order\OrderService;
use App\Tests\Support\ApiTestCase;

/**
 * Consultation et annulation (design-orders.md § 7) :
 *
 * - GET  /api/orders/me
 * - GET  /api/orders/{reference}
 * - POST /api/orders/{reference}/cancel
 * - GET  /api/events/{id}/orders
 *
 * Règle de confidentialité : une commande qui n'est pas la sienne répond 404
 * (et non 403), pour ne pas révéler qu'une référence existe.
 */
final class ReadOrderApiTest extends ApiTestCase
{
    private User $buyer;
    private User $otherBuyer;
    private User $organizerOwner;
    private Organizer $organizer;
    private Event $event;
    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->data->user('acheteur@test.mg');
        $this->otherBuyer = $this->data->user('autre@test.mg');
        $this->organizerOwner = $this->data->user('organisateur@test.mg');
        $this->organizer = $this->data->organizer('Tech Madagascar', $this->organizerOwner);
        $this->event = $this->data->event($this->organizer);
        $this->ticketType = $this->data->ticketType($this->event, 'Entrée générale', 20000, 100);
    }

    private function place(User $buyer, int $quantity = 1, ?TicketType $ticketType = null, ?Event $event = null): TicketOrder
    {
        $event ??= $this->event;
        $ticketType ??= $this->ticketType;

        return static::getContainer()->get(OrderService::class)->place(
            $buyer,
            (int) $event->getId(),
            [['ticketTypeId' => (int) $ticketType->getId(), 'quantity' => $quantity]]
        );
    }

    // ------------------------------------------------------ GET /api/orders/me

    public function testMesCommandesNeListentQueLesMiennesDeLaPlusRecenteALaPlusAncienne(): void
    {
        $ancienne = $this->place($this->buyer);
        $recente = $this->place($this->buyer);
        $this->place($this->otherBuyer);
        $this->db()->executeStatement(
            'UPDATE ticket_order SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $ancienne->getId()]
        );
        $references = [$recente->getReference(), $ancienne->getReference()];

        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me', as: $this->buyer), 200);

        self::assertSame(2, $body['data']['itemsTotal']);
        self::assertSame(1, $body['data']['currentPage']);
        self::assertSame(10, $body['data']['nombreParPage']);
        self::assertSame($references, array_column($body['data']['items'], 'reference'));
    }

    public function testMesCommandesSontPaginees(): void
    {
        $this->place($this->buyer);
        $this->place($this->buyer);
        $this->place($this->buyer);

        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?page=2&itemsPerPage=2', as: $this->buyer), 200);

        self::assertSame(3, $body['data']['itemsTotal']);
        self::assertSame(2, $body['data']['currentPage']);
        self::assertSame(2, $body['data']['nombreParPage']);
        self::assertCount(1, $body['data']['items']);
    }

    public function testMesCommandesExigentUneAuthentification(): void
    {
        self::assertStatus(401, $this->request('GET', '/api/orders/me'));
    }

    // ------------------------------------------------ GET /api/orders/{reference}

    public function testLAcheteurConsulteSaCommande(): void
    {
        $reference = $this->place($this->buyer, 2)->getReference();

        $body = $this->assertEnvelope($this->request('GET', '/api/orders/' . $reference, as: $this->buyer), 200);

        self::assertSame($reference, $body['data']['reference']);
        self::assertSame(40000, $body['data']['totalAmount']);
    }

    public function testLaCommandeDUnAutreRepond404(): void
    {
        $reference = $this->place($this->buyer)->getReference();

        $this->assertEnvelope($this->request('GET', '/api/orders/' . $reference, as: $this->otherBuyer), 404);
    }

    public function testUneReferenceInconnueRepond404(): void
    {
        $this->assertEnvelope($this->request('GET', '/api/orders/TU-ZZZZZZZZ', as: $this->buyer), 404);
    }

    public function testConsulterUneCommandeExigeUneAuthentification(): void
    {
        $reference = $this->place($this->buyer)->getReference();

        self::assertStatus(401, $this->request('GET', '/api/orders/' . $reference));
    }

    public function testUnMembreDeLOrganisationVoitLesCommandesDeSonEvenement(): void
    {
        $member = $this->data->user();
        $this->data->membership($member, $this->organizer, OrganizerRole::MEMBER);
        $reference = $this->place($this->buyer)->getReference();

        $this->assertEnvelope($this->request('GET', '/api/orders/' . $reference, as: $member), 200);
    }

    public function testLeSuperAdminVoitToutesLesCommandes(): void
    {
        $admin = $this->data->user('fondateur@test.mg', [User::ROLE_SUPER_ADMIN]);
        $reference = $this->place($this->buyer)->getReference();

        $this->assertEnvelope($this->request('GET', '/api/orders/' . $reference, as: $admin), 200);
    }

    // ----------------------------------------- POST /api/orders/{reference}/cancel

    public function testLAcheteurAnnuleSaCommande(): void
    {
        $order = $this->place($this->buyer, 3);

        $body = $this->assertEnvelope($this->request('POST', sprintf('/api/orders/%s/cancel', $order->getReference()), as: $this->buyer), 200);

        self::assertSame('cancelled', $body['data']['status']);
        self::assertSame(0, $this->fetchInt('SELECT quantite_reservee FROM ticket_type WHERE id = ?', [$this->ticketType->getId()]));
    }

    public function testAnnulerLaCommandeDUnAutreRepond404(): void
    {
        $order = $this->place($this->buyer, 3);

        $this->assertEnvelope($this->request('POST', sprintf('/api/orders/%s/cancel', $order->getReference()), as: $this->otherBuyer), 404);
        self::assertSame(3, $this->fetchInt('SELECT quantite_reservee FROM ticket_type WHERE id = ?', [$this->ticketType->getId()]));
    }

    public function testAnnulerUneCommandePayeeRepond409(): void
    {
        $gratuit = $this->data->ticketType($this->event, 'Zone Presse', 0);
        $order = $this->place($this->buyer, 1, $gratuit);

        $this->assertEnvelope($this->request('POST', sprintf('/api/orders/%s/cancel', $order->getReference()), as: $this->buyer), 409);
    }

    public function testAnnulerExigeUneAuthentification(): void
    {
        $order = $this->place($this->buyer);

        self::assertStatus(401, $this->request('POST', sprintf('/api/orders/%s/cancel', $order->getReference())));
    }

    // ------------------------------------------------ GET /api/events/{id}/orders

    public function testLOrganisateurListeLesCommandesDeSonEvenement(): void
    {
        $this->place($this->buyer, 2);
        $this->place($this->otherBuyer, 1);
        // Une commande sur un autre événement ne doit pas apparaître.
        $ailleurs = $this->data->event();
        $this->place($this->buyer, 1, $this->data->ticketType($ailleurs), $ailleurs);

        $body = $this->assertEnvelope($this->request('GET', sprintf('/api/events/%d/orders', $this->event->getId()), as: $this->organizerOwner), 200);

        self::assertSame(2, $body['data']['itemsTotal']);
        self::assertEqualsCanonicalizing(
            ['acheteur@test.mg', 'autre@test.mg'],
            array_map(static fn (array $order) => $order['buyer']['email'], $body['data']['items'])
        );
    }

    public function testUnNonMembreNePeutPasListerLesCommandesDeLEvenement(): void
    {
        $this->place($this->buyer);

        $this->assertEnvelope($this->request('GET', sprintf('/api/events/%d/orders', $this->event->getId()), as: $this->otherBuyer), 403);
    }

    public function testListerLesCommandesDUnEvenementExigeUneAuthentification(): void
    {
        self::assertStatus(401, $this->request('GET', sprintf('/api/events/%d/orders', $this->event->getId())));
    }

    public function testListerLesCommandesDUnEvenementInexistantRepond404(): void
    {
        $this->assertEnvelope($this->request('GET', '/api/events/999999/orders', as: $this->organizerOwner), 404);
    }
}
