<?php

namespace App\Tests\Integration\Order;

use App\Entity\TicketOrder;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use App\Service\Order\OrderService;
use App\Tests\Support\OrderTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Envoi d'une commande : `OrderService::place()`.
 * Référence : docs/database/design-orders.md § 3 et § 4.
 */
final class PlaceOrderTest extends OrderTestCase
{
    // ------------------------------------------------------------ cas nominal

    public function testUneCommandePayanteReserveLesPlacesEnAttenteDePaiement(): void
    {
        $buyer = $this->data->user('acheteur@test.mg');
        $event = $this->data->event();
        $standard = $this->data->ticketType($event, 'Entrée générale', 20000, 100);
        $vip = $this->data->ticketType($event, 'VIP', 50000, 10);

        $before = new \DateTimeImmutable('-1 second');
        $order = $this->orders()->place($buyer, $event->getId(), [
            self::line($standard, 2),
            self::line($vip, 1),
        ]);

        self::assertNotNull($order->getId());
        self::assertSame(OrderStatus::PENDING, $order->getStatus());
        self::assertSame($event->getId(), $order->getEvent()->getId());
        self::assertSame($buyer->getId(), $order->getUser()->getId());
        self::assertSame(90000, $order->getTotalAmount());
        self::assertSame('MGA', $order->getCurrency());
        self::assertMatchesRegularExpression('/^TU-[A-HJ-NP-Z2-9]{8}$/', $order->getReference());
        self::assertNull($order->getPaidAt());
        self::assertCount(2, $order->getItems());

        // Réservation de 15 minutes.
        $expected = $before->modify(sprintf('+%d minutes', OrderService::RESERVATION_MINUTES));
        self::assertNotNull($order->getExpiresAt());
        self::assertEqualsWithDelta($expected->getTimestamp(), $order->getExpiresAt()->getTimestamp(), 5);

        // Les places sont prises, aucun billet n'est encore émis.
        self::assertSame(2, $this->reserved($standard));
        self::assertSame(1, $this->reserved($vip));
        self::assertSame(0, $this->countTicketsOf($order));
        self::assertSame('pending', $this->orderStatus($order));
    }

    public function testLesCoordonneesDeLAcheteurSontCopieesSurLaCommande(): void
    {
        $buyer = $this->data->user('copie@test.mg');
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);

        $order = $this->placeOrder($buyer, $event, $ticketType);

        self::assertSame('copie@test.mg', $order->getBuyerEmail());
        self::assertSame($buyer->getFirstname(), $order->getBuyerFirstname());
        self::assertSame($buyer->getLastname(), $order->getBuyerLastname());
        self::assertSame($buyer->getPhone(), $order->getBuyerPhone());
    }

    public function testLePrixEstFigeAuMomentDeLAchat(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event, 'Entrée générale', 20000);

        $orderId = $this->placeOrder($buyer, $event, $ticketType, 2)->getId();

        // L'organisateur change le prix et le nom du tarif après la vente.
        $this->db()->executeStatement(
            "UPDATE ticket_type SET prix = 99000, name = 'Renommé' WHERE id = ?",
            [$ticketType->getId()]
        );
        $this->em()->clear();

        $order = $this->em()->find(TicketOrder::class, $orderId);
        $item = $order->getItems()->first();

        self::assertSame(20000, $item->getUnitPrice());
        self::assertSame('Entrée générale', $item->getTicketTypeName());
        self::assertSame(40000, $item->getLineTotal());
        self::assertSame(40000, $order->getTotalAmount());
    }

    public function testUneCommandeGratuiteEstPayeeEtEmetSesBilletsImmediatement(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $presse = $this->data->ticketType($event, 'Zone Presse', 0, 20);

        $order = $this->placeOrder($buyer, $event, $presse, 3);

        self::assertSame(OrderStatus::PAID, $order->getStatus());
        self::assertSame(0, $order->getTotalAmount());
        self::assertNotNull($order->getPaidAt());
        self::assertSame(3, $this->reserved($presse));
        self::assertSame(3, $this->countTicketsOf($order));

        $codes = [];
        foreach ($order->getItems() as $item) {
            foreach ($item->getTickets() as $ticket) {
                self::assertSame(TicketStatus::VALID, $ticket->getStatus());
                self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16,32}$/', $ticket->getCode());
                $codes[] = $ticket->getCode();
            }
        }
        self::assertCount(3, array_unique($codes), 'Chaque billet a un code unique.');
    }

    public function testOnPeutCommanderPendantUnEvenementEnCours(): void
    {
        // D-03 : la vente reste ouverte jusqu'à la fin de l'événement.
        $event = $this->data->event(startsAt: '-1 hour', durationHours: 3);
        $ticketType = $this->data->ticketType($event);

        $order = $this->placeOrder($this->data->user(), $event, $ticketType);

        self::assertSame(OrderStatus::PENDING, $order->getStatus());
    }

    public function testOnPeutCommanderExactementLeMaximumParCommande(): void
    {
        $event = $this->data->event();
        $a = $this->data->ticketType($event, 'A', 1000);
        $b = $this->data->ticketType($event, 'B', 1000);

        $order = $this->orders()->place($this->data->user(), $event->getId(), [
            self::line($a, OrderService::MAX_TICKETS_PER_ORDER - 1),
            self::line($b, 1),
        ]);

        self::assertSame(OrderService::MAX_TICKETS_PER_ORDER, $order->getTicketCount());
    }

    // ------------------------------------------------------ stock et quotas

    public function testOnPeutVendreJusquALaDernierePlace(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event, 'VIP', 50000, 5);

        $this->placeOrder($this->data->user(), $event, $ticketType, 5);

        self::assertSame(5, $this->reserved($ticketType));

        $exception = $this->assertOrderError(409, fn () => $this->placeOrder($this->data->user(), $event, $ticketType, 1));
        self::assertStringContainsString('VIP', $exception->getMessage(), 'Le message nomme le tarif épuisé.');
        self::assertSame(5, $this->reserved($ticketType));
    }

    public function testUnStockInsuffisantAnnuleToutLaCommande(): void
    {
        $event = $this->data->event();
        $libre = $this->data->ticketType($event, 'Entrée générale', 20000, 100);
        $presqueComplet = $this->data->ticketType($event, 'VIP', 50000, 2);

        $this->assertOrderError(409, fn () => $this->orders()->place($this->data->user(), $event->getId(), [
            self::line($libre, 4),          // réservable…
            self::line($presqueComplet, 3), // …mais celle-ci ne l'est pas
        ]));

        // Rien ne reste à moitié fait : ni place prise, ni commande créée.
        self::assertSame(0, $this->reserved($libre));
        self::assertSame(0, $this->reserved($presqueComplet));
        self::assertSame(0, $this->countOrders());
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM order_item'));
    }

    /**
     * Le contrôle de stock doit être fait **en SQL**, pas sur l'entité en
     * mémoire : on simule ici une vente concurrente écrite par une autre
     * requête pendant que l'EntityManager garde une copie périmée du tarif.
     */
    public function testLeStockEstVerifieEnBaseEtNonSurUneEntitePerimee(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event, 'VIP', 50000, 3);

        // Une autre requête vend les 3 places ; l'entité chargée ici croit encore à 0.
        $this->db()->executeStatement(
            'UPDATE ticket_type SET quantite_reservee = quantite_max WHERE id = ?',
            [$ticketType->getId()]
        );
        self::assertSame(0, $ticketType->getQuantiteReservee(), 'Pré-condition : copie en mémoire périmée.');

        $this->assertOrderError(409, fn () => $this->placeOrder($this->data->user(), $event, $ticketType, 1));
        self::assertSame(3, $this->reserved($ticketType));
    }

    public function testLesPlacesExpireesRedeviennentDisponibles(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event, 'VIP', 50000, 2);

        $this->placeOrder($this->data->user(), $event, $ticketType, 2);
        $this->orders()->expirePending(new \DateTimeImmutable('+1 hour'));

        $order = $this->placeOrder($this->data->user(), $event, $ticketType, 2);

        self::assertSame(OrderStatus::PENDING, $order->getStatus());
        self::assertSame(2, $this->reserved($ticketType));
    }

    // ------------------------------------------------------- idempotence

    public function testRejouerLaMemeCleRenvoieLaMemeCommande(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);

        $first = $this->placeOrder($buyer, $event, $ticketType, 2, 'cle-reseau-instable');
        $replay = $this->placeOrder($buyer, $event, $ticketType, 2, 'cle-reseau-instable');

        self::assertSame($first->getId(), $replay->getId());
        self::assertSame(1, $this->countOrders());
        self::assertSame(2, $this->reserved($ticketType), 'Les places ne sont réservées qu\'une fois.');
    }

    public function testLaCleDIdempotenceEstPropreAChaqueAcheteur(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);

        $this->placeOrder($this->data->user(), $event, $ticketType, 1, 'meme-cle');
        $this->placeOrder($this->data->user(), $event, $ticketType, 1, 'meme-cle');

        self::assertSame(2, $this->countOrders());
    }

    public function testSansCleChaqueEnvoiCreeUneCommande(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);

        $this->placeOrder($buyer, $event, $ticketType);
        $this->placeOrder($buyer, $event, $ticketType);

        self::assertSame(2, $this->countOrders());
    }

    // ------------------------------------------------------------ refus

    public function testEvenementInexistant(): void
    {
        $this->assertOrderError(404, fn () => $this->orders()->place($this->data->user(), 999999, [
            ['ticketTypeId' => 1, 'quantity' => 1],
        ]));
    }

    public function testUnBrouillonNEstPasEnVenteEtResteInvisible(): void
    {
        $event = $this->data->event(published: false);
        $ticketType = $this->data->ticketType($event);

        // 404 et non 403 : on ne révèle pas l'existence d'un événement non publié.
        $this->assertOrderError(404, fn () => $this->placeOrder($this->data->user(), $event, $ticketType));
        self::assertSame(0, $this->reserved($ticketType));
    }

    public function testUnEvenementTermineNEstPlusEnVente(): void
    {
        $event = $this->data->event(startsAt: '-5 hours', durationHours: 3);
        $ticketType = $this->data->ticketType($event);

        $this->assertOrderError(409, fn () => $this->placeOrder($this->data->user(), $event, $ticketType));
        self::assertSame(0, $this->reserved($ticketType));
    }

    public function testUnTarifDUnAutreEvenementEstRefuse(): void
    {
        $event = $this->data->event();
        $this->data->ticketType($event);
        $autreEvenement = $this->data->event();
        $tarifEtranger = $this->data->ticketType($autreEvenement);

        $this->assertOrderError(400, fn () => $this->placeOrder($this->data->user(), $event, $tarifEtranger));
        self::assertSame(0, $this->reserved($tarifEtranger));
    }

    public static function invalidLinesProvider(): iterable
    {
        yield 'aucune ligne' => [static fn (int $a, int $b) => []];
        yield 'quantité nulle' => [static fn (int $a, int $b) => [['ticketTypeId' => $a, 'quantity' => 0]]];
        yield 'quantité négative' => [static fn (int $a, int $b) => [['ticketTypeId' => $a, 'quantity' => -2]]];
        yield 'tarif en double' => [static fn (int $a, int $b) => [
            ['ticketTypeId' => $a, 'quantity' => 1],
            ['ticketTypeId' => $a, 'quantity' => 1],
        ]];
        yield 'plus de 10 billets au total' => [static fn (int $a, int $b) => [
            ['ticketTypeId' => $a, 'quantity' => 6],
            ['ticketTypeId' => $b, 'quantity' => 5],
        ]];
        yield 'tarif inexistant' => [static fn (int $a, int $b) => [['ticketTypeId' => 999999, 'quantity' => 1]]];
    }

    /** @param callable(int, int): array $lines */
    #[DataProvider('invalidLinesProvider')]
    public function testLignesInvalides(callable $lines): void
    {
        $event = $this->data->event();
        $a = $this->data->ticketType($event, 'A');
        $b = $this->data->ticketType($event, 'B');

        $this->assertOrderError(400, fn () => $this->orders()->place(
            $this->data->user(),
            $event->getId(),
            $lines($a->getId(), $b->getId())
        ));

        self::assertSame(0, $this->countOrders());
        self::assertSame(0, $this->reserved($a));
        self::assertSame(0, $this->reserved($b));
    }
}
