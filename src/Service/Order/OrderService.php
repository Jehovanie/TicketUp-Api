<?php

namespace App\Service\Order;

use App\Entity\Event;
use App\Entity\OrderItem;
use App\Entity\TicketOrder;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Exception\OrderException;
use App\Repository\EventRepository;
use App\Repository\TicketOrderRepository;
use App\Repository\TicketTypeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Commande de billets : toutes les écritures passent par ici.
 *
 * C'est ce service qui garantit les invariants du modèle :
 * - on ne vend jamais plus que `ticket_type.quantite_max` ;
 * - `ticket_type.quantite_reservee` = somme des quantités des commandes
 *   `pending` et `paid` — chaque transition qui libère des places les rend
 *   une fois et une seule ;
 * - les montants sont calculés à partir des prix en base, jamais reçus du client.
 *
 * Les transactions sont ouvertes sur la connexion (et non `wrapInTransaction`)
 * pour qu'un refus métier laisse l'EntityManager utilisable.
 *
 * @see docs/database/design-orders.md § 3 et § 4
 */
class OrderService
{
    /** Durée pendant laquelle une commande en attente bloque ses places. */
    public const RESERVATION_MINUTES = 15;

    /** Nombre maximal de billets par commande, tous tarifs confondus. */
    public const MAX_TICKETS_PER_ORDER = 10;

    private const MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $events,
        private readonly TicketTypeRepository $ticketTypes,
        private readonly TicketOrderRepository $orders,
        private readonly OrderReferenceGenerator $generator,
    ) {}

    /**
     * Envoie une commande : réserve les places et l'enregistre « en attente »,
     * ou « payée » avec ses billets si elle est gratuite.
     *
     * Rejouer la même `$idempotencyKey` pour le même acheteur renvoie la
     * commande déjà créée, sans rien réserver de plus.
     *
     * @param list<array{ticketTypeId: int, quantity: int}> $lines
     */
    public function place(User $buyer, int $eventId, array $lines, ?string $idempotencyKey = null): TicketOrder
    {
        if ($idempotencyKey !== null) {
            $existing = $this->orders->findOneByUserAndIdempotencyKey($buyer, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $now = new \DateTimeImmutable();
        $event = $this->eventOnSale($eventId, $now);
        $quantities = $this->normalizeLines($lines);
        $ticketTypes = $this->ticketTypesOf($event, array_keys($quantities));

        $order = (new TicketOrder())
            ->setBuyer($buyer)
            ->setEvent($event)
            ->setIdempotencyKey($idempotencyKey)
            ->setReference($this->uniqueReference());

        foreach ($quantities as $ticketTypeId => $quantity) {
            $order->addItem(OrderItem::fromTicketType($ticketTypes[$ticketTypeId], $quantity));
        }

        if ($order->getTotalAmount() === 0) {
            $order->markPaid($now, $this->generator->ticketCode(...));
        } else {
            $order->setExpiresAt($now->modify(sprintf('+%d minutes', self::RESERVATION_MINUTES)));
        }

        $this->inTransaction(function () use ($event, $quantities, $ticketTypes, $order) {
            // Ordre fixe (par identifiant) : deux commandes croisées ne peuvent
            // pas se bloquer mutuellement en verrouillant les tarifs.
            foreach ($quantities as $ticketTypeId => $quantity) {
                $this->reserve($event, $ticketTypes[$ticketTypeId], $quantity);
            }

            $this->em->persist($order);
            $this->em->flush();
        });

        $this->refreshCounters($ticketTypes);

        return $order;
    }

    /**
     * Annulation par l'acheteur, tant que la commande n'est pas payée.
     * Les places sont rendues.
     */
    public function cancel(TicketOrder $order, User $actor): TicketOrder
    {
        if ($order->getUser()?->getId() !== $actor->getId()) {
            throw OrderException::commandeIntrouvable((string) $order->getReference());
        }

        $this->inTransaction(function () use ($order) {
            // Relecture verrouillée : le statut en mémoire peut être périmé
            // (expiration ou paiement traités par une autre requête).
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $this->assertStatus($order, OrderStatus::PENDING);

            $this->releaseSeats($order->getId());
            $order->markCancelled(new \DateTimeImmutable());
            $this->em->flush();
        });

        return $order;
    }

    /**
     * Confirme le paiement : la commande passe à « payée » et ses billets sont
     * émis. Idempotent — une notification de paiement rejouée est sans effet.
     */
    public function markAsPaid(TicketOrder $order): TicketOrder
    {
        $this->inTransaction(function () use ($order) {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);

            if ($order->getStatus() === OrderStatus::PAID) {
                return;
            }

            $this->assertStatus($order, OrderStatus::PENDING);
            $order->markPaid(new \DateTimeImmutable(), $this->generator->ticketCode(...));
            $this->em->flush();
        });

        return $order;
    }

    /**
     * Expire les commandes en attente dont le délai est dépassé et rend leurs
     * places. Appelée chaque minute par `app:order:expire`.
     *
     * Écrite en SQL : un traitement de masse n'a pas à hydrater d'entités, et
     * `SKIP LOCKED` laisse de côté une commande en cours de paiement ou
     * d'annulation plutôt que de l'attendre (elle sera reprise au passage suivant).
     *
     * @return int nombre de commandes expirées
     */
    public function expirePending(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $connection = $this->em->getConnection();

        return $this->inTransaction(function () use ($connection, $now): int {
            $ids = $connection->fetchFirstColumn(
                "SELECT id FROM ticket_order
                 WHERE status = 'pending' AND expires_at < :now
                 ORDER BY id
                 FOR UPDATE SKIP LOCKED",
                ['now' => $now->format('Y-m-d H:i:s')]
            );

            foreach ($ids as $id) {
                $this->releaseSeats((int) $id);
            }

            if ($ids !== []) {
                $connection->executeStatement(
                    "UPDATE ticket_order SET status = 'expired', updated_at = :now WHERE id IN (:ids)",
                    ['now' => $now->format('Y-m-d H:i:s'), 'ids' => array_map('intval', $ids)],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                );
            }

            return count($ids);
        });
    }

    // ---------------------------------------------------------------- outils

    private function eventOnSale(int $eventId, \DateTimeImmutable $now): Event
    {
        $event = $this->events->find($eventId);

        // Un brouillon répond comme un événement inexistant : on ne révèle pas
        // ce qui n'est pas publié.
        if ($event === null || $event->isStatus() !== true) {
            throw OrderException::evenementIntrouvable($eventId);
        }

        // La vente reste ouverte jusqu'à la fin de l'événement (D-03).
        if ($event->getEndAt() <= $now) {
            throw OrderException::venteTerminee((string) $event->getTitle());
        }

        return $event;
    }

    /**
     * Contrôle les lignes et les ramène à [ticketTypeId => quantité], triées.
     *
     * @return array<int, int>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw OrderException::aucuneLigne();
        }

        $quantities = [];
        foreach ($lines as $line) {
            $ticketTypeId = (int) ($line['ticketTypeId'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw OrderException::quantiteInvalide($ticketTypeId);
            }

            if (isset($quantities[$ticketTypeId])) {
                throw OrderException::tarifEnDouble($ticketTypeId);
            }

            $quantities[$ticketTypeId] = $quantity;
        }

        $total = array_sum($quantities);
        if ($total > self::MAX_TICKETS_PER_ORDER) {
            throw OrderException::tropDeBillets($total);
        }

        ksort($quantities);

        return $quantities;
    }

    /**
     * @param int[] $ids
     * @return array<int, TicketType> indexés par identifiant
     */
    private function ticketTypesOf(Event $event, array $ids): array
    {
        $found = [];
        foreach ($this->ticketTypes->findBy(['id' => $ids]) as $ticketType) {
            $found[$ticketType->getId()] = $ticketType;
        }

        foreach ($ids as $id) {
            if (!isset($found[$id]) || $found[$id]->getEvent()?->getId() !== $event->getId()) {
                throw OrderException::tarifInconnu($id);
            }
        }

        return $found;
    }

    /**
     * Réservation atomique : la condition est évaluée par PostgreSQL sur la
     * ligne verrouillée, jamais sur la copie en mémoire (qui peut être périmée).
     */
    private function reserve(Event $event, TicketType $ticketType, int $quantity): void
    {
        $connection = $this->em->getConnection();

        $updated = $connection->executeStatement(
            'UPDATE ticket_type
             SET quantite_reservee = quantite_reservee + :quantity
             WHERE id = :id
               AND event_id = :event
               AND quantite_reservee + :quantity <= quantite_max',
            ['quantity' => $quantity, 'id' => $ticketType->getId(), 'event' => $event->getId()]
        );

        if ($updated === 0) {
            $available = (int) $connection->fetchOne(
                'SELECT GREATEST(quantite_max - quantite_reservee, 0) FROM ticket_type WHERE id = ?',
                [$ticketType->getId()]
            );

            throw OrderException::stockInsuffisant((string) $ticketType->getName(), $available);
        }
    }

    /** Rend les places d'une commande (à appeler une seule fois par commande). */
    private function releaseSeats(int $orderId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE ticket_type t
             SET quantite_reservee = t.quantite_reservee - i.quantity
             FROM order_item i
             WHERE i.order_id = :order AND i.ticket_type_id = t.id',
            ['order' => $orderId]
        );
    }

    private function assertStatus(TicketOrder $order, OrderStatus $expected): void
    {
        if ($order->getStatus() !== $expected) {
            throw OrderException::transitionImpossible((string) $order->getReference(), $order->getStatus()->label());
        }
    }

    private function uniqueReference(): string
    {
        for ($attempt = 0; $attempt < self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $reference = $this->generator->generate();
            if (!$this->orders->referenceExists($reference)) {
                return $reference;
            }
        }

        throw new \RuntimeException('Impossible de générer une référence de commande unique.');
    }

    /**
     * Le compteur est écrit en SQL : on recharge les tarifs pour que la suite
     * de la requête (réponse, sérialisation) voie la valeur réelle.
     *
     * @param TicketType[] $ticketTypes
     */
    private function refreshCounters(array $ticketTypes): void
    {
        foreach ($ticketTypes as $ticketType) {
            $this->em->refresh($ticketType);
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function inTransaction(callable $operation): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
