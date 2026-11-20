<?php

namespace App\Tests\Functional\Order;

use App\Entity\Event;
use App\Entity\TicketType;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `POST /api/orders` — envoi d'une commande (design-orders.md § 4 et § 7).
 *
 * Corps : { "eventId": int, "items": [ { "ticketTypeId": int, "quantity": int } ] }
 * En-tête facultatif : Idempotency-Key.
 */
final class PlaceOrderApiTest extends ApiTestCase
{
    private Event $event;
    private TicketType $standard;
    private TicketType $vip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = $this->data->event();
        $this->standard = $this->data->ticketType($this->event, 'Entrée générale', 20000, 100);
        $this->vip = $this->data->ticketType($this->event, 'VIP', 50000, 2);
    }

    private function payload(?array $lines = null): array
    {
        return [
            'eventId' => $this->event->getId(),
            'items' => $lines ?? [
                ['ticketTypeId' => $this->standard->getId(), 'quantity' => 2],
                ['ticketTypeId' => $this->vip->getId(), 'quantity' => 1],
            ],
        ];
    }

    public function testSansAuthentificationLaCommandeEstRefusee(): void
    {
        self::assertStatus(401, $this->request('POST', '/api/orders', $this->payload()));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM ticket_order'));
    }

    public function testCommandeCreeeRenvoieLaCommandeEnveloppee(): void
    {
        $buyer = $this->data->user();

        $body = $this->assertEnvelope($this->request('POST', '/api/orders', $this->payload(), $buyer), 201);
        $order = $body['data'];

        self::assertMatchesRegularExpression('/^TU-[A-HJ-NP-Z2-9]{8}$/', $order['reference']);
        self::assertArrayNotHasKey('id', $order, 'L\'identifiant séquentiel n\'est jamais exposé.');
        self::assertSame('pending', $order['status']);
        self::assertSame(90000, $order['totalAmount']);
        self::assertSame('MGA', $order['currency']);
        self::assertNotNull($order['expiresAt']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $order['expiresAt']));
        self::assertNull($order['paidAt']);
        self::assertSame($this->event->getId(), $order['event']['id']);
        self::assertSame([], $order['tickets']);

        self::assertCount(2, $order['items']);
        $lines = array_column($order['items'], null, 'ticketTypeId');
        self::assertSame(
            ['ticketTypeId' => $this->standard->getId(), 'name' => 'Entrée générale', 'unitPrice' => 20000, 'quantity' => 2, 'lineTotal' => 40000],
            array_intersect_key($lines[$this->standard->getId()], array_flip(['ticketTypeId', 'name', 'unitPrice', 'quantity', 'lineTotal']))
        );

        self::assertSame(2, $this->fetchInt('SELECT quantite_reservee FROM ticket_type WHERE id = ?', [$this->standard->getId()]));
    }

    public function testLesPrixEnvoyesParLeClientSontIgnores(): void
    {
        $payload = $this->payload([
            ['ticketTypeId' => $this->standard->getId(), 'quantity' => 1, 'unitPrice' => 1, 'lineTotal' => 1],
        ]);
        $payload['totalAmount'] = 1;

        $body = $this->assertEnvelope($this->request('POST', '/api/orders', $payload, $this->data->user()), 201);

        self::assertSame(20000, $body['data']['totalAmount']);
        self::assertSame(20000, $body['data']['items'][0]['unitPrice']);
    }

    public function testUneCommandeGratuiteRenvoieSesBillets(): void
    {
        $presse = $this->data->ticketType($this->event, 'Zone Presse', 0, 10);

        $body = $this->assertEnvelope($this->request('POST', '/api/orders', $this->payload([
            ['ticketTypeId' => $presse->getId(), 'quantity' => 2],
        ]), $this->data->user()), 201);

        self::assertSame('paid', $body['data']['status']);
        self::assertNotNull($body['data']['paidAt']);
        self::assertCount(2, $body['data']['tickets']);
        foreach ($body['data']['tickets'] as $ticket) {
            self::assertNotEmpty($ticket['code']);
            self::assertSame('valid', $ticket['status']);
            self::assertSame('Zone Presse', $ticket['ticketTypeName']);
        }
    }

    public function testStockInsuffisantRepond409(): void
    {
        $body = $this->assertEnvelope($this->request('POST', '/api/orders', $this->payload([
            ['ticketTypeId' => $this->vip->getId(), 'quantity' => 3],
        ]), $this->data->user()), 409);

        self::assertNull($body['data']);
        self::assertStringContainsString('VIP', $body['message']);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM ticket_order'));
    }

    public function testEvenementInexistantRepond404(): void
    {
        $payload = $this->payload();
        $payload['eventId'] = 999999;

        $this->assertEnvelope($this->request('POST', '/api/orders', $payload, $this->data->user()), 404);
    }

    public function testBrouillonRepond404(): void
    {
        $draft = $this->data->event(published: false);
        $ticketType = $this->data->ticketType($draft);

        $this->assertEnvelope($this->request('POST', '/api/orders', [
            'eventId' => $draft->getId(),
            'items' => [['ticketTypeId' => $ticketType->getId(), 'quantity' => 1]],
        ], $this->data->user()), 404);
    }

    public static function malformedProvider(): iterable
    {
        yield 'eventId manquant' => [['items' => [['ticketTypeId' => 1, 'quantity' => 1]]]];
        yield 'eventId non entier' => [['eventId' => 'abc', 'items' => [['ticketTypeId' => 1, 'quantity' => 1]]]];
        yield 'items manquant' => [['eventId' => 1]];
        yield 'items non tableau' => [['eventId' => 1, 'items' => 'tout']];
        yield 'ligne sans ticketTypeId' => [['eventId' => 1, 'items' => [['quantity' => 1]]]];
        yield 'quantité non entière' => [['eventId' => 1, 'items' => [['ticketTypeId' => 1, 'quantity' => 'deux']]]];
    }

    #[DataProvider('malformedProvider')]
    public function testCorpsMalFormeRepond400(array $payload): void
    {
        $this->assertEnvelope($this->request('POST', '/api/orders', $payload, $this->data->user()), 400);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM ticket_order'));
    }

    public function testCorpsQuiNEstPasDuJsonRepond400(): void
    {
        $this->assertEnvelope($this->request('POST', '/api/orders', '{pas du json', $this->data->user()), 400);
    }

    public function testRejouerAvecLaMemeCleRenvoieLaMemeCommandeSansLaDupliquer(): void
    {
        $buyer = $this->data->user();
        $headers = ['Idempotency-Key' => 'b1c2d3-reseau-coupe'];

        $first = $this->assertEnvelope($this->request('POST', '/api/orders', $this->payload(), $buyer, $headers), 201);
        $replay = $this->assertEnvelope($this->request('POST', '/api/orders', $this->payload(), $buyer, $headers), 200);

        self::assertSame($first['data']['reference'], $replay['data']['reference']);
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM ticket_order'));
        self::assertSame(2, $this->fetchInt('SELECT quantite_reservee FROM ticket_type WHERE id = ?', [$this->standard->getId()]));
    }

    public function testUneCleDIdempotenceTropLongueEstRefusee(): void
    {
        $this->assertEnvelope($this->request('POST', '/api/orders', $this->payload(), $this->data->user(), [
            'Idempotency-Key' => str_repeat('x', 65), // colonne de 64 caractères
        ]), 400);
    }
}
