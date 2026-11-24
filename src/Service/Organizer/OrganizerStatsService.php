<?php

namespace App\Service\Organizer;

use App\Entity\Organizer;
use App\Entity\TicketOrder;
use App\Enum\OrderStatus;
use App\Exception\OrganizerException;
use Doctrine\DBAL\Connection;

/**
 * Statistiques de vente d'une organisation (`GET /api/organizers/{id}/stats`).
 *
 * Règles de calcul :
 * - une **vente** est une commande au statut `paid` ; `pending`, `cancelled`,
 *   `expired` et `refunded` ne comptent ni dans les billets vendus ni dans les
 *   revenus (ils ne sont repris que dans la ventilation `ordersByStatus`) ;
 * - revenus = somme de `ticket_order.total_amount` (ariary, `TicketOrder::CURRENCY`) ;
 * - billets vendus = somme de `order_item.quantity` ;
 * - les **totaux** et le **top** couvrent toute la vie de l'organisation ; seule la
 *   **série** est bornée par la période demandée ;
 * - la série est datée par `paid_at`, dans le fuseau d'enregistrement des dates
 *   (voir Q-04), et complétée par des zéros : tous les jours (ou mois) de la
 *   période y figurent, un graphique n'a pas de trou à combler.
 *
 * Lecture seule, en SQL agrégé : aucun chargement d'entité de commande.
 */
final class OrganizerStatsService
{
    public const DAY = 'day';
    public const MONTH = 'month';

    private const DEFAULT_TOP = 5;
    private const MAX_TOP = 10;
    private const MAX_DAYS = 366;
    private const MAX_MONTHS = 36;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{granularity: string, from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    public function resolvePeriod(?string $granularity, ?string $from, ?string $to, ?\DateTimeImmutable $today = null): array
    {
        $granularity = $granularity === null || $granularity === '' ? self::DAY : $granularity;
        if (!in_array($granularity, [self::DAY, self::MONTH], true)) {
            throw OrganizerException::filtreInvalide('granularity', [self::DAY, self::MONTH]);
        }

        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $end = $this->date('to', $to) ?? $today;
        $start = $this->date('from', $from)
            ?? ($granularity === self::DAY ? $end->modify('-29 days') : $end->modify('first day of this month')->modify('-11 months'));

        if ($granularity === self::MONTH) {
            $start = $start->modify('first day of this month');
        }

        if ($start > $end) {
            throw OrganizerException::parametreInvalide('« from » doit précéder ou égaler « to ».');
        }

        $span = $granularity === self::DAY
            ? (int) $start->diff($end)->days + 1
            : ((int) $end->format('Y') - (int) $start->format('Y')) * 12 + (int) $end->format('n') - (int) $start->format('n') + 1;
        $max = $granularity === self::DAY ? self::MAX_DAYS : self::MAX_MONTHS;

        if ($span > $max) {
            throw OrganizerException::parametreInvalide(sprintf(
                'La période demandée couvre %d %s ; le maximum est %d.',
                $span,
                $granularity === self::DAY ? 'jours' : 'mois',
                $max
            ));
        }

        return ['granularity' => $granularity, 'from' => $start, 'to' => $end];
    }

    public function resolveTop(?string $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_TOP;
        }

        if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > self::MAX_TOP) {
            throw OrganizerException::parametreInvalide(sprintf('« top » doit être un entier entre 1 et %d.', self::MAX_TOP));
        }

        return (int) $raw;
    }

    public function compute(Organizer $organizer, array $period, int $top, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $id = $organizer->getId();

        return [
            'organizer' => ['id' => $id, 'name' => $organizer->getName()],
            'currency' => TicketOrder::CURRENCY,
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'events' => $this->events($id, $now),
            'sales' => $this->sales($id),
            'series' => [
                'granularity' => $period['granularity'],
                'from' => $period['from']->format('Y-m-d'),
                'to' => $period['to']->format('Y-m-d'),
                'points' => $this->series($id, $period),
            ],
            'topEvents' => $this->topEvents($id, $top),
        ];
    }

    private function events(int $id, \DateTimeImmutable $now): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status) AS published,
                    COUNT(*) FILTER (WHERE NOT status) AS draft,
                    COUNT(*) FILTER (WHERE started_at > :now) AS upcoming,
                    COUNT(*) FILTER (WHERE started_at <= :now AND end_at >= :now) AS ongoing,
                    COUNT(*) FILTER (WHERE end_at < :now) AS past
             FROM event WHERE organizer_id = :id',
            ['id' => $id, 'now' => $now->format('Y-m-d H:i:s')]
        );

        return array_map('intval', $row);
    }

    private function sales(int $id): array
    {
        $paid = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue
             FROM ticket_order o JOIN event e ON e.id = o.event_id
             WHERE e.organizer_id = :id AND o.status = 'paid'",
            ['id' => $id]
        );

        $tickets = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(i.quantity), 0)
             FROM order_item i
             JOIN ticket_order o ON o.id = i.order_id
             JOIN event e ON e.id = o.event_id
             WHERE e.organizer_id = :id AND o.status = 'paid'",
            ['id' => $id]
        );

        $byStatus = array_fill_keys(array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases()), 0);
        $rows = $this->db->fetchAllKeyValue(
            'SELECT o.status, COUNT(*) FROM ticket_order o JOIN event e ON e.id = o.event_id
             WHERE e.organizer_id = :id GROUP BY o.status',
            ['id' => $id]
        );
        foreach ($rows as $status => $count) {
            $byStatus[$status] = (int) $count;
        }

        return [
            'ordersPaid' => (int) $paid['orders'],
            'ticketsSold' => $tickets,
            'revenue' => (int) $paid['revenue'],
            'ordersByStatus' => $byStatus,
        ];
    }

    private function series(int $id, array $period): array
    {
        $daily = $period['granularity'] === self::DAY;
        $format = $daily ? 'YYYY-MM-DD' : 'YYYY-MM';
        $params = [
            'id' => $id,
            'from' => $period['from']->format('Y-m-d 00:00:00'),
            // Borne haute exclusive : le lendemain du dernier jour demandé.
            'to' => $period['to']->modify('+1 day')->format('Y-m-d 00:00:00'),
        ];

        $orders = $this->db->fetchAllAssociative(
            "SELECT to_char(o.paid_at, '$format') AS bucket, COUNT(*) AS orders, SUM(o.total_amount) AS revenue
             FROM ticket_order o JOIN event e ON e.id = o.event_id
             WHERE e.organizer_id = :id AND o.status = 'paid' AND o.paid_at >= :from AND o.paid_at < :to
             GROUP BY bucket",
            $params
        );
        $tickets = $this->db->fetchAllKeyValue(
            "SELECT to_char(o.paid_at, '$format') AS bucket, SUM(i.quantity)
             FROM order_item i
             JOIN ticket_order o ON o.id = i.order_id
             JOIN event e ON e.id = o.event_id
             WHERE e.organizer_id = :id AND o.status = 'paid' AND o.paid_at >= :from AND o.paid_at < :to
             GROUP BY bucket",
            $params
        );
        $byBucket = [];
        foreach ($orders as $row) {
            $byBucket[$row['bucket']] = $row;
        }

        $points = [];
        $cursor = $period['from'];
        while ($cursor <= $period['to']) {
            $key = $cursor->format($daily ? 'Y-m-d' : 'Y-m');
            $points[] = [
                'date' => $key,
                'orders' => (int) ($byBucket[$key]['orders'] ?? 0),
                'ticketsSold' => (int) ($tickets[$key] ?? 0),
                'revenue' => (int) ($byBucket[$key]['revenue'] ?? 0),
            ];
            $cursor = $cursor->modify($daily ? '+1 day' : 'first day of next month');
        }

        return $points;
    }

    private function topEvents(int $id, int $top): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.id, e.title, SUM(i.quantity) AS tickets_sold, COUNT(DISTINCT o.id) AS orders, SUM(i.line_total) AS revenue
             FROM order_item i
             JOIN ticket_order o ON o.id = i.order_id
             JOIN event e ON e.id = o.event_id
             WHERE e.organizer_id = :id AND o.status = 'paid'
             GROUP BY e.id, e.title
             ORDER BY tickets_sold DESC, revenue DESC, e.id ASC
             LIMIT $top",
            ['id' => $id]
        );

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'ticketsSold' => (int) $r['tickets_sold'],
            'orders' => (int) $r['orders'],
            'revenue' => (int) $r['revenue'],
        ], $rows);
    }

    private function date(string $name, ?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            throw OrganizerException::parametreInvalide(sprintf('« %s » doit être une date au format AAAA-MM-JJ.', $name));
        }

        return $date;
    }
}
