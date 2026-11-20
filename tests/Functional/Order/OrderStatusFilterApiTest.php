<?php

namespace App\Tests\Functional\Order;

use App\Entity\Event;
use App\Entity\TicketOrder;
use App\Entity\TicketType;
use App\Entity\User;
use App\Service\Order\OrderService;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Filtre par statut des listes de commandes, pour les onglets du front
 * (en attente, payées, annulées, expirées) :
 *
 * - GET /api/orders/me?status=…
 * - GET /api/events/{id}/orders?status=…
 *
 * `status` accepte une valeur ou plusieurs séparées par des virgules. Chaque
 * réponse donne aussi `countsByStatus` : le nombre de commandes par statut,
 * indépendant du filtre, pour afficher les compteurs des onglets en un appel.
 */
final class OrderStatusFilterApiTest extends ApiTestCase
{
    private User $buyer;
    private User $owner;
    private Event $event;
    private TicketType $ticketType;

    /** @var array<string, string> statut => référence */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->data->user('acheteur@test.mg');
        $this->owner = $this->data->user('organisateur@test.mg');
        $this->event = $this->data->event($this->data->organizer('Org', $this->owner));
        $this->ticketType = $this->data->ticketType($this->event, 'Standard', 20000, 100);

        // Une commande par statut, plus une deuxième en attente.
        $this->refs['pending'] = $this->place()->getReference();
        $this->place();

        $paid = $this->place();
        $this->service()->markAsPaid($paid);
        $this->refs['paid'] = $paid->getReference();

        $cancelled = $this->place();
        $this->service()->cancel($cancelled, $this->buyer);
        $this->refs['cancelled'] = $cancelled->getReference();

        $expired = $this->place();
        // Date calculée en PHP, comme `expirePending()` (et non NOW() de PostgreSQL : fuseaux différents, Q-04).
        $this->db()->executeStatement(
            'UPDATE ticket_order SET expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $expired->getId()]
        );
        $this->service()->expirePending();
        $this->refs['expired'] = $expired->getReference();

        // Commande d'un autre acheteur : jamais dans « mes commandes ».
        $this->place($this->data->user('autre@test.mg'));
    }

    // ---------------------------------------------------- GET /api/orders/me

    #[DataProvider('statutsSimples')]
    public function testMesCommandesFiltreesParStatut(string $status, int $expected): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?status=' . $status, as: $this->buyer), 200);

        self::assertSame($expected, $body['data']['itemsTotal'], 'itemsTotal compte les commandes filtrées.');
        self::assertCount($expected, $body['data']['items']);
        self::assertSame([$status], array_values(array_unique(array_column($body['data']['items'], 'status'))));
    }

    public static function statutsSimples(): iterable
    {
        yield 'en attente' => ['pending', 2];
        yield 'payées' => ['paid', 1];
        yield 'annulées' => ['cancelled', 1];
        yield 'expirées' => ['expired', 1];
    }

    public function testUnStatutSansCommandeRenvoieUneListeVide(): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?status=refunded', as: $this->buyer), 200);

        self::assertSame(0, $body['data']['itemsTotal']);
        self::assertSame([], $body['data']['items']);
    }

    public function testPlusieursStatutsSeparesParDesVirgules(): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?status=cancelled,expired', as: $this->buyer), 200);

        self::assertSame(2, $body['data']['itemsTotal']);
        self::assertEqualsCanonicalizing(
            [$this->refs['cancelled'], $this->refs['expired']],
            array_column($body['data']['items'], 'reference')
        );
    }

    public function testSansFiltreToutesLesCommandesSontRenvoyees(): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me', as: $this->buyer), 200);

        self::assertSame(5, $body['data']['itemsTotal']);
    }

    public function testLeFiltreSeCombineAvecLaPagination(): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?status=pending&page=2&itemsPerPage=1', as: $this->buyer), 200);

        self::assertSame(2, $body['data']['itemsTotal']);
        self::assertSame(2, $body['data']['currentPage']);
        self::assertCount(1, $body['data']['items']);
        self::assertSame('pending', $body['data']['items'][0]['status']);
    }

    public function testLesCompteursParStatutIgnorentLeFiltre(): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?status=paid', as: $this->buyer), 200);

        self::assertSame(
            ['pending' => 2, 'paid' => 1, 'cancelled' => 1, 'expired' => 1, 'refunded' => 0],
            $body['data']['countsByStatus']
        );
    }

    #[DataProvider('statutsInvalides')]
    public function testUnStatutInconnuEstRefuse(string $query): void
    {
        $body = $this->assertEnvelope($this->request('GET', '/api/orders/me?' . $query, as: $this->buyer), 400);

        self::assertStringContainsString('pending', $body['message'], 'Le message liste les valeurs acceptées.');
    }

    public static function statutsInvalides(): iterable
    {
        yield 'valeur inconnue' => ['status=livree'];
        yield 'une valeur inconnue dans la liste' => ['status=paid,livree'];
        yield 'vide' => ['status='];
        yield 'majuscules' => ['status=PAID'];
        yield 'tableau' => ['status[]=paid'];
    }

    // -------------------------------------------- GET /api/events/{id}/orders

    public function testLesCommandesDUnEvenementFiltreesParStatut(): void
    {
        $uri = sprintf('/api/events/%d/orders?status=pending', $this->event->getId());
        $body = $this->assertEnvelope($this->request('GET', $uri, as: $this->owner), 200);

        // 2 en attente de l'acheteur + 1 de l'autre acheteur.
        self::assertSame(3, $body['data']['itemsTotal']);
        self::assertSame(['pending'], array_values(array_unique(array_column($body['data']['items'], 'status'))));
        self::assertSame(
            ['pending' => 3, 'paid' => 1, 'cancelled' => 1, 'expired' => 1, 'refunded' => 0],
            $body['data']['countsByStatus']
        );
    }

    public function testUnStatutInconnuEstRefusePourLesCommandesDUnEvenement(): void
    {
        $uri = sprintf('/api/events/%d/orders?status=livree', $this->event->getId());

        $this->assertEnvelope($this->request('GET', $uri, as: $this->owner), 400);
    }

    // ---------------------------------------------------------------- outils

    private function service(): OrderService
    {
        return static::getContainer()->get(OrderService::class);
    }

    private function place(?User $buyer = null): TicketOrder
    {
        return $this->service()->place(
            $buyer ?? $this->buyer,
            (int) $this->event->getId(),
            [['ticketTypeId' => (int) $this->ticketType->getId(), 'quantity' => 1]]
        );
    }
}
