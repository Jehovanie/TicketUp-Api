<?php

namespace App\Tests\Integration\EventSubscriber;

use App\Tests\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Une suppression refusée par une clé étrangère doit répondre `409` dans
 * l'enveloppe `{ message, status, data }`, avec un message clair, et non une
 * erreur 500 qui exposerait le SQL.
 *
 * Les exceptions sont de vraies erreurs PostgreSQL, puis passées au
 * répartiteur d'événements du kernel : c'est le branchement réel qui est testé.
 */
final class IntegrityConstraintSubscriberTest extends DatabaseTestCase
{
    public function testUneReferenceEncoreUtiliseeDonneUn409Clair(): void
    {
        $event = $this->data->event();

        // NO ACTION → SQLSTATE 23503 (converti par DBAL en ForeignKeyConstraintViolationException).
        $exception = $this->captureSqlError('DELETE FROM location WHERE id = ?', [$event->getLocation()->getId()]);
        self::assertSame('23503', $exception->getSQLState());

        $this->assertConflictResponse($exception);
    }

    public function testUneSuppressionBloqueeParRestrictDonneUn409Clair(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $this->db()->executeStatement(
            "INSERT INTO ticket_order (reference, user_id, event_id, status, total_amount, currency, buyer_email, buyer_firstname, buyer_lastname, created_at, updated_at)
             VALUES ('TU-RESTRICT', NULL, ?, 'paid', 0, 'MGA', 'a@test.mg', 'A', 'B', NOW(), NOW())",
            [$event->getId()]
        );
        $orderId = $this->fetchInt("SELECT id FROM ticket_order WHERE reference = 'TU-RESTRICT'");
        $this->db()->executeStatement(
            "INSERT INTO order_item (order_id, ticket_type_id, ticket_type_name, unit_price, quantity, line_total, created_at)
             VALUES (?, ?, 'Gratuit', 0, 1, 0, NOW())",
            [$orderId, $ticketType->getId()]
        );

        // ON DELETE RESTRICT → SQLSTATE 23001, que DBAL ne convertit pas en classe dédiée.
        $exception = $this->captureSqlError('DELETE FROM ticket_type WHERE id = ?', [$ticketType->getId()]);
        self::assertSame('23001', $exception->getSQLState());

        $this->assertConflictResponse($exception);
    }

    public function testUneErreurEnveloppeeEstAussiReconnue(): void
    {
        $event = $this->data->event();
        $sqlError = $this->captureSqlError('DELETE FROM location WHERE id = ?', [$event->getLocation()->getId()]);

        $this->assertConflictResponse(new \RuntimeException('Échec de la suppression', 0, $sqlError));
    }

    public function testLesAutresErreursSqlNeSontPasInterceptees(): void
    {
        $this->data->user('doublon@test.mg');
        $other = $this->data->user('autre@test.mg');

        // Violation d'unicité (23505) : hors du périmètre de ce subscriber.
        $exception = $this->captureSqlError('UPDATE "user" SET email = ? WHERE id = ?', ['doublon@test.mg', $other->getId()]);
        self::assertSame('23505', $exception->getSQLState());

        self::assertNull($this->conflictOrNull($exception));
    }

    public function testLesErreursNonSqlNeSontPasInterceptees(): void
    {
        self::assertNull($this->conflictOrNull(new \RuntimeException('Autre chose')));
    }

    // ---------------------------------------------------------------- outils

    private function captureSqlError(string $sql, array $params): DriverException
    {
        try {
            $this->db()->executeStatement($sql, $params);
        } catch (DriverException $exception) {
            return $exception;
        }

        self::fail('La base aurait dû refuser la requête.');
    }

    private function dispatch(\Throwable $throwable): ExceptionEvent
    {
        $event = new ExceptionEvent(static::$kernel, Request::create('/api/test'), HttpKernelInterface::MAIN_REQUEST, $throwable);

        // Seul le subscriber testé est appelé : on vérifie qu'il est bien
        // branché sur kernel.exception, sans déclencher le rendu d'erreur de Symfony.
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        foreach ($dispatcher->getListeners(KernelEvents::EXCEPTION) as $listener) {
            if (is_array($listener) && $listener[0] instanceof \App\EventSubscriber\IntegrityConstraintSubscriber) {
                $listener($event, KernelEvents::EXCEPTION, $dispatcher);
            }
        }

        return $event;
    }

    /** Réponse posée par le subscriber, ou null s'il n'a pas réagi. */
    private function conflictOrNull(\Throwable $throwable): ?array
    {
        $response = $this->dispatch($throwable)->getResponse();

        return $response === null ? null : json_decode((string) $response->getContent(), true);
    }

    private function assertConflictResponse(\Throwable $throwable): void
    {
        $event = $this->dispatch($throwable);
        $response = $event->getResponse();

        self::assertNotNull($response, 'Le subscriber doit répondre à une violation de clé étrangère.');
        self::assertSame(409, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(409, $body['status']);
        self::assertNull($body['data']);
        self::assertStringContainsString('impossible', mb_strtolower($body['message']));
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $body['message'], 'Aucun détail SQL n’est exposé.');
        self::assertStringNotContainsStringIgnoringCase('constraint', $body['message']);
    }
}
