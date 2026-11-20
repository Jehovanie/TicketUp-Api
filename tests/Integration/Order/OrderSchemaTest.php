<?php

namespace App\Tests\Integration\Order;

use App\Entity\Event;
use App\Entity\TicketType;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Contraintes portées par la base elle-même (migration de la commande de
 * billets, design-orders.md § 5). Elles protègent les données même si un
 * futur code — ou un accès SQL direct — contourne `OrderService`.
 *
 * Écrit volontairement en SQL brut : ce sont les garde-fous de PostgreSQL qui
 * sont testés, pas l'ORM.
 */
final class OrderSchemaTest extends DatabaseTestCase
{
    private const CHECK_VIOLATION = '23514';
    private const UNIQUE_VIOLATION = '23505';
    /** `ON DELETE RESTRICT` lève 23001 ; `NO ACTION` lèverait 23503. */
    private const RESTRICT_VIOLATION = '23001';

    private User $buyer;
    private Event $event;
    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->data->user();
        $this->event = $this->data->event();
        $this->ticketType = $this->data->ticketType($this->event, 'Entrée générale', 20000, 10);
    }

    // ------------------------------------------------------------ ticket_type

    public function testUnTarifExistantDemarreAvecZeroPlaceReservee(): void
    {
        // Insertion sans la nouvelle colonne, comme les lignes antérieures à la migration.
        $id = $this->db()->fetchOne(
            "INSERT INTO ticket_type (event_id, name, prix, quantite_max, created_at, updated_at)
             VALUES (?, 'Ancien tarif', 1000, 5, NOW(), NOW()) RETURNING id",
            [$this->event->getId()]
        );

        self::assertSame(0, $this->fetchInt('SELECT quantite_reservee FROM ticket_type WHERE id = ?', [$id]));
    }

    public function testOnNePeutPasReserverPlusQueLeQuota(): void
    {
        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->db()->executeStatement(
            'UPDATE ticket_type SET quantite_reservee = quantite_max + 1 WHERE id = ?',
            [$this->ticketType->getId()]
        ));
    }

    public function testLesPlacesReserveesNePeuventPasDevenirNegatives(): void
    {
        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->db()->executeStatement(
            'UPDATE ticket_type SET quantite_reservee = -1 WHERE id = ?',
            [$this->ticketType->getId()]
        ));
    }

    public function testOnNePeutPasBaisserLeQuotaSousLesPlacesDejaPrises(): void
    {
        $this->db()->executeStatement('UPDATE ticket_type SET quantite_reservee = 8 WHERE id = ?', [$this->ticketType->getId()]);

        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->db()->executeStatement(
            'UPDATE ticket_type SET quantite_max = 5 WHERE id = ?',
            [$this->ticketType->getId()]
        ));
    }

    // ----------------------------------------------------------- ticket_order

    public function testStatutDeCommandeInconnuRefuse(): void
    {
        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertOrder(status: 'shipped'));
    }

    public function testMontantNegatifRefuse(): void
    {
        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertOrder(total: -1));
    }

    public function testUneCommandeEnAttenteDoitAvoirUneEcheance(): void
    {
        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertOrder(status: 'pending', expiresAt: null));
    }

    public function testLaReferenceEstUnique(): void
    {
        $this->insertOrder(reference: 'TU-AAAAAAAA');

        $this->assertSqlError(self::UNIQUE_VIOLATION, fn () => $this->insertOrder(reference: 'TU-AAAAAAAA'));
    }

    public function testLaCleDIdempotenceEstUniqueParAcheteur(): void
    {
        $this->insertOrder(idempotencyKey: 'cle-1');

        $this->assertSqlError(self::UNIQUE_VIOLATION, fn () => $this->insertOrder(idempotencyKey: 'cle-1'));
    }

    public function testLaMemeCleEstPermiseADeuxAcheteursDifferents(): void
    {
        $this->insertOrder(idempotencyKey: 'cle-partagee');
        $this->insertOrder(idempotencyKey: 'cle-partagee', userId: $this->data->user()->getId());

        self::assertSame(2, $this->fetchInt('SELECT COUNT(*) FROM ticket_order'));
    }

    // ------------------------------------------------------------- order_item

    public function testQuantiteNulleRefusee(): void
    {
        $orderId = $this->insertOrder();

        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertItem($orderId, quantity: 0, lineTotal: 0));
    }

    public function testLeTotalDeLigneDoitEtrePrixFoisQuantite(): void
    {
        $orderId = $this->insertOrder();

        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertItem($orderId, unitPrice: 20000, quantity: 2, lineTotal: 1));
    }

    public function testUnSeulTarifParLigneDansUneCommande(): void
    {
        $orderId = $this->insertOrder();
        $this->insertItem($orderId);

        $this->assertSqlError(self::UNIQUE_VIOLATION, fn () => $this->insertItem($orderId));
    }

    // ----------------------------------------------------------------- ticket

    public function testStatutDeBilletInconnuRefuse(): void
    {
        $itemId = $this->insertItem($this->insertOrder());

        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertTicket($itemId, status: 'lost'));
    }

    public function testUnBilletUtiliseDoitAvoirUneDateDUtilisation(): void
    {
        $itemId = $this->insertItem($this->insertOrder());

        $this->assertSqlError(self::CHECK_VIOLATION, fn () => $this->insertTicket($itemId, status: 'used'));
    }

    public function testLeCodeDeBilletEstUnique(): void
    {
        $itemId = $this->insertItem($this->insertOrder(), quantity: 2, lineTotal: 40000);
        $this->insertTicket($itemId, code: 'CODEUNIQUE0000000001');

        $this->assertSqlError(self::UNIQUE_VIOLATION, fn () => $this->insertTicket($itemId, code: 'CODEUNIQUE0000000001'));
    }

    // ------------------------------------------------------------ suppressions

    public function testUnTarifVenduNePeutPasEtreSupprime(): void
    {
        $this->insertItem($this->insertOrder());

        $this->assertSqlError(self::RESTRICT_VIOLATION, fn () => $this->db()->executeStatement(
            'DELETE FROM ticket_type WHERE id = ?',
            [$this->ticketType->getId()]
        ));
    }

    public function testUnEvenementAvecDesCommandesNePeutPasEtreSupprime(): void
    {
        // Événement sans tarif : seule la commande peut bloquer la suppression
        // (sinon la FK de ticket_type la bloquerait avant, et le test ne
        // prouverait rien sur ticket_order).
        $event = $this->data->event();
        $this->insertOrder(eventId: $event->getId());

        $this->assertSqlError(self::RESTRICT_VIOLATION, fn () => $this->db()->executeStatement(
            'DELETE FROM event WHERE id = ?',
            [$event->getId()]
        ));
    }

    public function testLaCommandeSurvitALaSuppressionDuCompte(): void
    {
        $orderId = $this->insertOrder();

        $this->db()->executeStatement('DELETE FROM "user" WHERE id = ?', [$this->buyer->getId()]);

        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM ticket_order WHERE id = ?', [$orderId]));
        self::assertNull($this->fetchValue('SELECT user_id FROM ticket_order WHERE id = ?', [$orderId]));
        self::assertSame('acheteur@schema.mg', $this->fetchValue('SELECT buyer_email FROM ticket_order WHERE id = ?', [$orderId]));
    }

    public function testLesLignesEtBilletsSuiventLeurCommande(): void
    {
        $orderId = $this->insertOrder();
        $this->insertTicket($this->insertItem($orderId));

        $this->db()->executeStatement('DELETE FROM ticket_order WHERE id = ?', [$orderId]);

        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM order_item'));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM ticket'));
    }

    // ---------------------------------------------------------------- outils

    private function insertOrder(
        string $status = 'pending',
        int $total = 20000,
        ?string $expiresAt = '+15 minutes',
        ?string $reference = null,
        ?string $idempotencyKey = null,
        ?int $userId = null,
        ?int $eventId = null,
    ): int {
        static $n = 0;
        $n++;

        return (int) $this->db()->fetchOne(
            'INSERT INTO ticket_order
                (reference, user_id, event_id, status, total_amount, currency,
                 buyer_email, buyer_firstname, buyer_lastname, idempotency_key,
                 expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'MGA\', \'acheteur@schema.mg\', \'Prénom\', \'Nom\', ?, ?, NOW(), NOW())
             RETURNING id',
            [
                $reference ?? sprintf('TU-T%07d', $n),
                $userId ?? $this->buyer->getId(),
                $eventId ?? $this->event->getId(),
                $status,
                $total,
                $idempotencyKey,
                $expiresAt === null ? null : (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function insertItem(int $orderId, int $unitPrice = 20000, int $quantity = 1, ?int $lineTotal = null): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO order_item (order_id, ticket_type_id, ticket_type_name, unit_price, quantity, line_total, created_at)
             VALUES (?, ?, 'Entrée générale', ?, ?, ?, NOW()) RETURNING id",
            [$orderId, $this->ticketType->getId(), $unitPrice, $quantity, $lineTotal ?? $unitPrice * $quantity]
        );
    }

    private function insertTicket(int $itemId, string $status = 'valid', ?string $code = null): int
    {
        return (int) $this->db()->fetchOne(
            'INSERT INTO ticket (order_item_id, code, status, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW()) RETURNING id',
            [$itemId, $code ?? bin2hex(random_bytes(16)), $status]
        );
    }

    private function assertSqlError(string $sqlState, callable $statement): void
    {
        try {
            $statement();
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState(), $exception->getMessage());

            return;
        }

        self::fail(sprintf('La base aurait dû refuser l\'écriture (SQLSTATE %s).', $sqlState));
    }
}
