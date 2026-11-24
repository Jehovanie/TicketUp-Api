<?php

namespace App\Tests\Functional\Organizer;

use App\Entity\Event;
use App\Entity\Organizer;
use App\Entity\TicketOrder;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Service\Order\OrderService;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/organizers/{id}/stats.
 *
 * Jeu de données (organisation « Tech ») :
 * - Concert (à venir) : tarif 20 000 Ar. Commandes payées : 2 billets (J-1) et 3 billets (J-1) ;
 *   une commande annulée (1 billet) et une en attente (1 billet) ne comptent pas ;
 * - Festival (passé) : tarif 50 000 Ar. Une commande payée de 1 billet, il y a 40 jours ;
 * - Brouillon : aucune vente.
 * Une autre organisation a aussi une vente payée : elle ne doit jamais apparaître.
 */
final class OrganizerStatsApiTest extends ApiTestCase
{
    private User $owner;
    private User $member;
    private User $stranger;
    private Organizer $organizer;
    private Event $concert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->data->user();
        $this->member = $this->data->user();
        $this->stranger = $this->data->user();
        $buyer = $this->data->user();

        $this->organizer = $this->data->organizer('Tech', $this->owner);
        $this->data->membership($this->member, $this->organizer, OrganizerRole::MEMBER);

        $this->concert = $this->data->event($this->organizer, true, '+5 days');
        $concertTicket = $this->data->ticketType($this->concert, 'Standard', 20000, 100);
        // La vente est refusée sur un événement passé : on le crée à venir, puis on le recule.
        $festival = $this->data->event($this->organizer, true, '+5 days');
        $festivalTicket = $this->data->ticketType($festival, 'VIP', 50000, 100);
        $this->data->event($this->organizer, false, '+9 days');

        $this->paid($buyer, $this->concert, $concertTicket, 2, '-1 day');
        $this->paid($buyer, $this->concert, $concertTicket, 3, '-1 day');
        $this->paid($buyer, $festival, $festivalTicket, 1, '-40 days');
        $this->db()->executeStatement(
            'UPDATE event SET started_at = ?, end_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s', strtotime('-40 days')), date('Y-m-d H:i:s', strtotime('-40 days +3 hours')), $festival->getId()]
        );

        $cancelled = $this->place($buyer, $this->concert, $concertTicket, 1);
        $this->orders()->cancel($cancelled, $buyer);
        $this->place($buyer, $this->concert, $concertTicket, 1); // en attente

        $other = $this->data->organizer('Autre', $this->stranger);
        $otherEvent = $this->data->event($other);
        $this->paid($buyer, $otherEvent, $this->data->ticketType($otherEvent, 'X', 99999, 10), 1, '-1 day');
    }

    private function orders(): OrderService
    {
        return static::getContainer()->get(OrderService::class);
    }

    private function place(User $buyer, Event $event, TicketType $type, int $quantity): TicketOrder
    {
        return $this->orders()->place($buyer, (int) $event->getId(), [['ticketTypeId' => (int) $type->getId(), 'quantity' => $quantity]]);
    }

    private function paid(User $buyer, Event $event, TicketType $type, int $quantity, string $paidAt): void
    {
        $order = $this->orders()->markAsPaid($this->place($buyer, $event, $type, $quantity));
        $this->db()->executeStatement(
            'UPDATE ticket_order SET paid_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($paidAt))->format('Y-m-d 12:00:00'), $order->getId()]
        );
    }

    private function stats(string $query = '', ?User $as = null): \Symfony\Component\HttpFoundation\Response
    {
        return $this->request('GET', '/api/organizers/' . $this->organizer->getId() . '/stats' . $query, null, $as ?? $this->member);
    }

    private function data_(string $query = ''): array
    {
        $response = $this->stats($query);
        self::assertStatus(200, $response);

        return $this->json($response)['data'];
    }

    public function testTotals(): void
    {
        $data = $this->data_();

        self::assertSame('MGA', $data['currency']);
        self::assertSame(['id' => $this->organizer->getId(), 'name' => 'Tech'], $data['organizer']);
        self::assertSame(
            ['total' => 3, 'published' => 2, 'draft' => 1, 'upcoming' => 2, 'ongoing' => 0, 'past' => 1],
            $data['events']
        );
        self::assertSame(3, $data['sales']['ordersPaid']);
        self::assertSame(6, $data['sales']['ticketsSold'], '2 + 3 + 1 : annulée et en attente exclues.');
        self::assertSame(2 * 20000 + 3 * 20000 + 50000, $data['sales']['revenue']);
        self::assertSame(
            ['pending' => 1, 'paid' => 3, 'cancelled' => 1, 'expired' => 0, 'refunded' => 0],
            $data['sales']['ordersByStatus']
        );
    }

    public function testDailySeriesIsDenseAndDefaultsToLast30Days(): void
    {
        $series = $this->data_()['series'];

        self::assertSame('day', $series['granularity']);
        self::assertCount(30, $series['points']);
        self::assertSame(date('Y-m-d'), $series['to']);
        self::assertSame(date('Y-m-d', strtotime('-29 days')), $series['from']);

        $byDate = array_column($series['points'], null, 'date');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        self::assertSame(['date' => $yesterday, 'orders' => 2, 'ticketsSold' => 5, 'revenue' => 100000], $byDate[$yesterday]);
        self::assertSame(0, $byDate[date('Y-m-d')]['orders'], 'Les jours sans vente valent 0.');
        self::assertSame(100000, array_sum(array_column($series['points'], 'revenue')), 'La vente d’il y a 40 jours est hors période.');
    }

    public function testMonthlySeries(): void
    {
        $series = $this->data_('?granularity=month&from=' . date('Y-m-01', strtotime('-2 months')))['series'];

        self::assertSame('month', $series['granularity']);
        $keys = array_column($series['points'], 'date');
        self::assertSame(date('Y-m'), end($keys));
        self::assertCount(3, $keys);
        self::assertSame(
            150000,
            array_sum(array_column($series['points'], 'revenue')),
            'Ventes d’hier (100 000) + d’il y a 40 jours (50 000, dans les 3 mois).'
        );
    }

    public function testExplicitPeriodExcludesSales(): void
    {
        $far = date('Y-m-d', strtotime('-200 days'));
        $series = $this->data_("?from=$far&to=" . date('Y-m-d', strtotime('-190 days')))['series'];

        self::assertCount(11, $series['points']);
        self::assertSame(0, array_sum(array_column($series['points'], 'orders')));
        self::assertSame(6, $this->data_("?from=$far&to=$far")['sales']['ticketsSold'], 'Les totaux ignorent la période.');
    }

    public function testTopEventsRankedBySalesAndLimited(): void
    {
        $top = $this->data_()['topEvents'];

        self::assertCount(2, $top, 'Le brouillon sans vente n’apparaît pas.');
        self::assertSame($this->concert->getId(), $top[0]['id']);
        self::assertSame(['ticketsSold' => 5, 'orders' => 2, 'revenue' => 100000], array_intersect_key($top[0], array_flip(['ticketsSold', 'orders', 'revenue'])));
        self::assertSame(1, $top[1]['ticketsSold']);
        self::assertSame(50000, $top[1]['revenue']);

        self::assertCount(1, $this->data_('?top=1')['topEvents']);
    }

    #[DataProvider('badQueries')]
    public function testRejectsInvalidParameters(string $query, string $messagePart): void
    {
        $response = $this->stats($query);

        self::assertStatus(400, $response);
        self::assertStringContainsString($messagePart, $this->json($response)['message']);
    }

    public static function badQueries(): iterable
    {
        yield 'granularité inconnue' => ['?granularity=week', 'granularity'];
        yield 'date mal formée' => ['?from=hier', 'AAAA-MM-JJ'];
        yield 'date impossible' => ['?from=2026-02-31', 'AAAA-MM-JJ'];
        yield 'from après to' => ['?from=2026-05-02&to=2026-05-01', 'précéder'];
        yield 'période trop longue (jours)' => ['?from=2020-01-01&to=2026-01-01', 'maximum est 366'];
        yield 'période trop longue (mois)' => ['?granularity=month&from=2020-01-01&to=2026-01-01', 'maximum est 36'];
        yield 'top nul' => ['?top=0', 'top'];
        yield 'top trop grand' => ['?top=11', 'top'];
        yield 'top texte' => ['?top=abc', 'top'];
        yield 'top tableau' => ['?top[]=1', 'top'];
    }

    public function testAccessControl(): void
    {
        self::assertStatus(401, $this->request('GET', '/api/organizers/' . $this->organizer->getId() . '/stats'));
        self::assertStatus(403, $this->stats('', $this->stranger));
        self::assertStatus(404, $this->request('GET', '/api/organizers/999999/stats', null, $this->owner));

        $root = $this->data->user(roles: [User::ROLE_SUPER_ADMIN]);
        self::assertStatus(200, $this->stats('', $root));
    }

    public function testOrganizationWithoutSalesGetsZerosNotErrors(): void
    {
        $empty = $this->data->organizer('Vide', $this->owner);

        $response = $this->request('GET', '/api/organizers/' . $empty->getId() . '/stats', null, $this->owner);

        self::assertStatus(200, $response);
        $data = $this->json($response)['data'];
        self::assertSame(0, $data['events']['total']);
        self::assertSame(0, $data['sales']['revenue']);
        self::assertSame([], $data['topEvents']);
        self::assertCount(30, $data['series']['points']);
    }
}
