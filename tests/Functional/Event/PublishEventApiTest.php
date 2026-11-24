<?php

namespace App\Tests\Functional\Event;

use App\Entity\Event;
use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Tests\Support\ApiTestCase;

/** POST /api/events/{id}/publish. */
final class PublishEventApiTest extends ApiTestCase
{
    private User $owner;
    private User $admin;
    private User $member;
    private User $stranger;
    private Organizer $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->data->user();
        $this->admin = $this->data->user();
        $this->member = $this->data->user();
        $this->stranger = $this->data->user();
        $this->organizer = $this->data->organizer('Tech', $this->owner);
        $this->data->membership($this->admin, $this->organizer, OrganizerRole::ADMIN);
        $this->data->membership($this->member, $this->organizer, OrganizerRole::MEMBER);
    }

    private function draft(string $startsAt = '+10 days', bool $withTicket = true): Event
    {
        $event = $this->data->event($this->organizer, false, $startsAt);
        if ($withTicket) {
            $this->data->ticketType($event);
        }

        return $event;
    }

    private function publish(Event|int $event, ?User $as): \Symfony\Component\HttpFoundation\Response
    {
        return $this->request('POST', '/api/events/' . ($event instanceof Event ? $event->getId() : $event) . '/publish', null, $as);
    }

    private function isPublished(Event $event): bool
    {
        return (bool) $this->db()->fetchOne('SELECT status FROM event WHERE id = ?', [$event->getId()]);
    }

    public function testAdminAndOwnerPublishADraft(): void
    {
        // Créés avant la première requête : elle détache les entités du test.
        $drafts = [[$this->owner, $this->draft()], [$this->admin, $this->draft()]];

        foreach ($drafts as [$user, $event]) {
            $response = $this->publish($event, $user);

            self::assertStatus(200, $response);
            $body = $this->json($response);
            self::assertSame(200, $body['status']);
            self::assertStringContainsString('est maintenant publié', $body['message']);
            self::assertSame(['id' => $event->getId(), 'title' => $event->getTitle(), 'published' => true, 'alreadyPublished' => false], $body['data']);
            self::assertTrue($this->isPublished($event));
        }
    }

    public function testSuperAdminCanPublishAnyEvent(): void
    {
        $event = $this->draft();

        self::assertStatus(200, $this->publish($event, $this->data->user(roles: [User::ROLE_SUPER_ADMIN])));
        self::assertTrue($this->isPublished($event));
    }

    public function testPublishingIsIdempotent(): void
    {
        $event = $this->draft();
        self::assertStatus(200, $this->publish($event, $this->admin));

        $again = $this->publish($event, $this->admin);

        self::assertStatus(200, $again);
        $body = $this->json($again);
        self::assertTrue($body['data']['alreadyPublished']);
        self::assertStringContainsString('déjà publié', $body['message']);
        self::assertTrue($this->isPublished($event));
    }

    public function testAlreadyPublishedAnswers200EvenWhenRulesWouldNowFail(): void
    {
        $event = $this->data->event($this->organizer, true, '-3 days'); // publié, sans billet, terminé

        self::assertStatus(200, $this->publish($event, $this->admin));
    }

    public function testRefusesBelowAdminAndLeavesTheDraftUntouched(): void
    {
        $event = $this->draft();

        self::assertStatus(403, $this->publish($event, $this->member));
        self::assertStatus(403, $this->publish($event, $this->stranger));
        self::assertFalse($this->isPublished($event));
    }

    public function testRightsAreCheckedAgainstTheEventsOwnOrganization(): void
    {
        $other = $this->data->organizer('Autre', $this->stranger);
        $foreign = $this->data->event($other, false);
        $this->data->ticketType($foreign);

        self::assertStatus(403, $this->publish($foreign, $this->owner), 'Responsable ailleurs ≠ droit ici.');
        self::assertFalse($this->isPublished($foreign));
    }

    public function testRequiresAuthentication(): void
    {
        self::assertStatus(401, $this->publish($this->draft(), null));
    }

    public function testUnknownEventIs404(): void
    {
        $response = $this->publish(999999, $this->owner);

        self::assertStatus(404, $response);
        self::assertSame(404, $this->json($response)['status']);
    }

    public function testRefusesAnEventWithoutTicketType(): void
    {
        $event = $this->draft(withTicket: false);

        $response = $this->publish($event, $this->admin);

        self::assertStatus(409, $response);
        self::assertStringContainsString('au moins un type de billet', $this->json($response)['message']);
        self::assertFalse($this->isPublished($event));
    }

    public function testRefusesAnEventAlreadyOver(): void
    {
        $event = $this->draft('-2 days');

        $response = $this->publish($event, $this->admin);

        self::assertStatus(409, $response);
        self::assertStringContainsString('déjà terminé', $this->json($response)['message']);
        self::assertFalse($this->isPublished($event));
    }

    public function testAnOngoingEventCanBePublished(): void
    {
        $event = $this->draft('-1 hour');

        self::assertStatus(200, $this->publish($event, $this->admin));
        self::assertTrue($this->isPublished($event));
    }

    public function testPublishedEventBecomesVisibleInThePublicList(): void
    {
        $event = $this->draft();
        self::assertNotContains($event->getId(), array_column($this->json($this->request('GET', '/api/events'))['data']['items'], 'id'));

        $this->publish($event, $this->admin);

        self::assertContains($event->getId(), array_column($this->json($this->request('GET', '/api/events'))['data']['items'], 'id'));
    }
}
