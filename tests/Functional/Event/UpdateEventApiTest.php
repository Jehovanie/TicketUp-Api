<?php

namespace App\Tests\Functional\Event;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Location;
use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/** PATCH /api/events/{id} : tout membre modifie, seuls admin+ publient. */
final class UpdateEventApiTest extends ApiTestCase
{
    private User $owner;
    private User $admin;
    private User $member;
    private User $stranger;
    private Organizer $organizer;
    private Event $draft;
    private Event $published;
    private Category $category;
    private Location $location;

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

        $this->draft = $this->data->event($this->organizer, false, '+10 days');
        $this->data->ticketType($this->draft);
        $this->published = $this->data->event($this->organizer, true, '+10 days');
        $this->data->ticketType($this->published);
        $this->category = $this->data->category();
        $this->location = $this->data->location(500);
    }

    private function patch(Event $event, array|string $body, ?User $as): Response
    {
        return $this->request('PATCH', '/api/events/' . $event->getId(), $body, $as);
    }

    private function row(Event $event): array
    {
        return $this->db()->fetchAssociative('SELECT * FROM event WHERE id = ?', [$event->getId()]);
    }

    // ------------------------------------------------------------------ Droits

    public function testEveryMemberRoleCanEditContent(): void
    {
        $events = [];
        foreach ([$this->member, $this->admin, $this->owner] as $user) {
            $events[] = [$user, $this->data->event($this->organizer, false)];
        }

        foreach ($events as $i => [$user, $event]) {
            self::assertStatus(200, $this->patch($event, ['title' => "Titre $i"], $user));
            self::assertSame("Titre $i", $this->row($event)['title']);
        }
    }

    public function testStrangerIs403AndNothingChanges(): void
    {
        self::assertStatus(403, $this->patch($this->draft, ['title' => 'Piraté'], $this->stranger));
        self::assertNotSame('Piraté', $this->row($this->draft)['title']);
    }

    public function testRightsAreCheckedAgainstTheEventsOwnOrganization(): void
    {
        $other = $this->data->organizer('Autre', $this->stranger);
        $foreign = $this->data->event($other, false);

        self::assertStatus(403, $this->patch($foreign, ['title' => 'X'], $this->owner));
    }

    public function testAuthenticationAndExistence(): void
    {
        self::assertStatus(401, $this->patch($this->draft, ['title' => 'X'], null));
        self::assertStatus(404, $this->request('PATCH', '/api/events/999999', ['title' => 'X'], $this->owner));
    }

    public function testSuperAdminCanEditAnyEventAndPublish(): void
    {
        $root = $this->data->user(roles: [User::ROLE_SUPER_ADMIN]);

        self::assertStatus(200, $this->patch($this->draft, ['title' => 'Root', 'status' => true], $root));
        self::assertTrue((bool) $this->row($this->draft)['status']);
    }

    // ------------------------------------------------------------- Publication

    public function testMemberCannotTouchPublicationStatus(): void
    {
        foreach ([true, false] as $value) {
            $response = $this->patch($this->draft, ['title' => 'Au passage', 'status' => $value], $this->member);

            self::assertStatus(403, $response);
            self::assertStringContainsString('responsable ou un administrateur', $this->json($response)['message']);
        }

        $row = $this->row($this->draft);
        self::assertFalse((bool) $row['status']);
        self::assertNotSame('Au passage', $row['title'], 'Rien n’est écrit : le refus porte sur toute la requête.');
    }

    public function testAdminAndOwnerPublishThroughPatch(): void
    {
        $drafts = [[$this->admin, $this->draft], [$this->owner, $this->data->event($this->organizer, false)]];
        $this->data->ticketType($drafts[1][1]);

        foreach ($drafts as [$user, $event]) {
            $response = $this->patch($event, ['status' => true], $user);

            self::assertStatus(200, $response);
            self::assertTrue($this->json($response)['data']['status']);
            self::assertTrue((bool) $this->row($event)['status']);
        }
    }

    public function testPublishingThroughPatchAppliesTheSameRules(): void
    {
        $noTicket = $this->data->event($this->organizer, false);

        $response = $this->patch($noTicket, ['status' => true], $this->admin);
        self::assertStatus(409, $response);
        self::assertStringContainsString('au moins un type de billet', $this->json($response)['message']);

        $response = $this->patch($this->draft, ['status' => true, 'startedAt' => '2020-01-01T10:00:00+03:00', 'endAt' => '2020-01-01T12:00:00+03:00'], $this->admin);
        self::assertStatus(409, $response);
        self::assertStringContainsString('déjà terminé', $this->json($response)['message']);
        self::assertFalse((bool) $this->row($this->draft)['status']);
        self::assertSame($this->draft->getTitle(), $this->row($this->draft)['title']);
    }

    public function testRulesAreJudgedOnTheValuesAfterTheEdit(): void
    {
        $ended = $this->data->event($this->organizer, false, '-3 days');
        $this->data->ticketType($ended);

        $future = (new \DateTimeImmutable('+5 days'))->format(DATE_ATOM);
        $response = $this->patch($ended, ['status' => true, 'startedAt' => (new \DateTimeImmutable('+4 days'))->format(DATE_ATOM), 'endAt' => $future], $this->admin);

        self::assertStatus(200, $response);
        self::assertTrue((bool) $this->row($ended)['status']);
    }

    public function testStatusTrueOnPublishedIsIdempotent(): void
    {
        self::assertStatus(200, $this->patch($this->published, ['status' => true], $this->admin));
        self::assertTrue((bool) $this->row($this->published)['status']);
    }

    public function testUnpublishingIsRefused(): void
    {
        $response = $this->patch($this->published, ['status' => false], $this->admin);

        self::assertStatus(409, $response);
        self::assertStringContainsString('brouillon', $this->json($response)['message']);
        self::assertTrue((bool) $this->row($this->published)['status']);
    }

    public function testStatusFalseOnADraftIsANoOp(): void
    {
        self::assertStatus(200, $this->patch($this->draft, ['status' => false, 'title' => 'Reste brouillon'], $this->admin));
        self::assertFalse((bool) $this->row($this->draft)['status']);
    }

    public function testPublishedEventCannotBeEndedByAnEdit(): void
    {
        $response = $this->patch($this->published, ['startedAt' => '2020-01-01T10:00:00+03:00', 'endAt' => '2020-01-01T12:00:00+03:00'], $this->member);

        self::assertStatus(409, $response);
        self::assertSame($this->published->getEndAt()->format('Y-m-d H:i'), (new \DateTimeImmutable($this->row($this->published)['end_at']))->format('Y-m-d H:i'));
    }

    // ----------------------------------------------------------------- Contenu

    public function testPartialUpdateOnlyTouchesGivenFields(): void
    {
        $before = $this->row($this->draft);

        $response = $this->patch($this->draft, [
            'title' => '  Nouveau titre  ',
            'description' => 'Nouvelle description',
            'category' => ['id' => $this->category->getId()],
            'location' => $this->location->getId(),
        ], $this->member);

        self::assertStatus(200, $response);
        $row = $this->row($this->draft);
        self::assertSame('Nouveau titre', $row['title']);
        self::assertSame('Nouvelle description', $row['description']);
        self::assertSame($this->category->getId(), (int) $row['category_id']);
        self::assertSame($this->location->getId(), (int) $row['location_id']);
        self::assertSame($before['started_at'], $row['started_at'], 'Champ absent : inchangé.');
        self::assertSame($before['organizer_id'], $row['organizer_id']);
        self::assertGreaterThanOrEqual($before['updated_at'], $row['updated_at']);

        $data = $this->json($response)['data'];
        self::assertSame('Nouveau titre', $data['title']);
        self::assertSame('Nouvelle description', $data['description']);
        self::assertFalse($data['status']);
    }

    public function testDatesAreParsedWithTheirOffset(): void
    {
        $start = new \DateTimeImmutable('+30 days 18:00');
        $end = $start->modify('+4 hours');

        self::assertStatus(200, $this->patch($this->draft, [
            'startedAt' => $start->format(DATE_ATOM),
            'endAt' => $end->format(DATE_ATOM),
        ], $this->member));

        $row = $this->row($this->draft);
        self::assertSame($start->format('Y-m-d H:i'), (new \DateTimeImmutable($row['started_at']))->format('Y-m-d H:i'));
        self::assertSame($end->format('Y-m-d H:i'), (new \DateTimeImmutable($row['end_at']))->format('Y-m-d H:i'));
    }

    public function testChangingOnlyOneDateIsCheckedAgainstTheOther(): void
    {
        $tooEarly = (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM); // avant le début (+10 j)

        $response = $this->patch($this->draft, ['endAt' => $tooEarly], $this->member);

        self::assertStatus(400, $response);
        self::assertStringContainsString('postérieure', $this->json($response)['message']);
    }

    public function testCategoryCanBeCleared(): void
    {
        self::assertNotNull($this->row($this->draft)['category_id']);

        self::assertStatus(200, $this->patch($this->draft, ['category' => null], $this->member));

        self::assertNull($this->row($this->draft)['category_id']);
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreRejectedWithoutWriting(array|string $body, string $messagePart): void
    {
        $response = $this->patch($this->draft, $body, $this->admin);

        self::assertStatus(400, $response);
        self::assertStringContainsString($messagePart, $this->json($response)['message']);
        self::assertSame($this->draft->getTitle(), $this->row($this->draft)['title']);
    }

    public static function invalidBodies(): iterable
    {
        yield 'corps vide' => [[], 'Aucun champ à modifier'];
        yield 'JSON invalide' => ['pas du json', 'Aucun champ à modifier'];
        yield 'champ inconnu seul' => [['foo' => 1], 'Aucun champ à modifier'];
        yield 'organisation' => [['organizer' => ['id' => 1]], 'organisation'];
        yield 'titre vide' => [['title' => '  '], 'title'];
        yield 'titre non texte' => [['title' => 12], 'title'];
        yield 'titre trop long' => [['title' => str_repeat('a', 151)], '150'];
        yield 'description non texte' => [['description' => ['x']], 'description'];
        yield 'date illisible' => [['startedAt' => 'demain soir'], 'startedAt'];
        yield 'date vide' => [['endAt' => ''], 'endAt'];
        yield 'fin avant début' => [['startedAt' => '2030-01-02T10:00:00+03:00', 'endAt' => '2030-01-01T10:00:00+03:00'], 'postérieure'];
        yield 'fin = début' => [['startedAt' => '2030-01-01T10:00:00+03:00', 'endAt' => '2030-01-01T10:00:00+03:00'], 'postérieure'];
        yield 'catégorie inconnue' => [['category' => 999999], 'catégorie'];
        yield 'catégorie mal formée' => [['category' => 'abc'], 'category'];
        yield 'lieu inconnu' => [['location' => ['id' => 999999]], 'lieu'];
        yield 'lieu nul' => [['location' => null], 'location'];
        yield 'status non booléen' => [['status' => 'published'], 'status'];
        yield 'valide + invalide : rien n’est écrit' => [['title' => 'Valide', 'startedAt' => 'nawak'], 'startedAt'];
    }
}
