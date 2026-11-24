<?php

namespace App\Tests\Functional\Organizer;

use App\Entity\Event;
use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/organizers/{id}/events et GET /api/organizers/{id}/member-candidates.
 */
final class OrganizerEventsAndSearchApiTest extends ApiTestCase
{
    private User $owner;
    private User $member;
    private User $stranger;
    private Organizer $organizer;
    private Organizer $other;

    /** @var array<string, Event> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->data->user();
        $this->member = $this->data->user();
        $this->stranger = $this->data->user();
        $this->organizer = $this->data->organizer('Tech Madagascar', $this->owner);
        $this->other = $this->data->organizer('Autre', $this->stranger);
        $this->data->membership($this->member, $this->organizer, OrganizerRole::MEMBER);

        $this->events['upcoming'] = $this->data->event($this->organizer, true, '+5 days');
        $this->events['later'] = $this->data->event($this->organizer, true, '+20 days');
        $this->events['ongoing'] = $this->data->event($this->organizer, true, '-1 hour', 5);
        $this->events['past'] = $this->data->event($this->organizer, true, '-10 days');
        $this->events['draft'] = $this->data->event($this->organizer, false, '+8 days');
        $this->events['foreign'] = $this->data->event($this->other, true, '+5 days');
    }

    private function ids(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return array_column($this->json($response)['data']['items'], 'id');
    }

    private function url(string $query = ''): string
    {
        return '/api/organizers/' . $this->organizer->getId() . '/events' . $query;
    }

    // -------------------------------------------------------------- Événements

    public function testListsOnlyThisOrganizersEventsDraftsIncluded(): void
    {
        $response = $this->request('GET', $this->url(), null, $this->member);

        self::assertStatus(200, $response);
        $data = $this->json($response)['data'];
        self::assertSame(5, $data['itemsTotal']);
        self::assertSame(1, $data['currentPage']);
        self::assertSame(10, $data['nombreParPage']);
        self::assertSame('Tech Madagascar', $data['organizer']['name']);
        self::assertNotContains($this->events['foreign']->getId(), $this->ids($response));
        self::assertContains($this->events['draft']->getId(), $this->ids($response));
    }

    public function testFiltersByStatusAndPeriod(): void
    {
        $draft = $this->request('GET', $this->url('?status=draft'), null, $this->owner);
        self::assertSame([$this->events['draft']->getId()], $this->ids($draft));

        $published = $this->json($this->request('GET', $this->url('?status=published'), null, $this->owner));
        self::assertSame(4, $published['data']['itemsTotal']);

        $upcoming = $this->request('GET', $this->url('?period=upcoming&sort=startedAt:asc'), null, $this->owner);
        self::assertSame(
            [$this->events['upcoming']->getId(), $this->events['draft']->getId(), $this->events['later']->getId()],
            $this->ids($upcoming),
            'Tri explicite : le plus proche d’abord.'
        );

        self::assertSame(
            [$this->events['ongoing']->getId()],
            $this->ids($this->request('GET', $this->url('?period=ongoing'), null, $this->owner))
        );
        self::assertSame(
            [$this->events['past']->getId()],
            $this->ids($this->request('GET', $this->url('?period=past'), null, $this->owner))
        );

        self::assertSame(
            [$this->events['upcoming']->getId(), $this->events['later']->getId()],
            $this->ids($this->request('GET', $this->url('?period=upcoming&status=published&sort=startedAt:asc'), null, $this->owner))
        );
    }

    public function testPaginates(): void
    {
        $page2 = $this->json($this->request('GET', $this->url('?itemsPerPage=2&page=2'), null, $this->owner))['data'];

        self::assertSame(5, $page2['itemsTotal']);
        self::assertSame(2, $page2['nombreParPage']);
        self::assertSame(2, $page2['currentPage']);
        self::assertCount(2, $page2['items']);

        $capped = $this->json($this->request('GET', $this->url('?itemsPerPage=500'), null, $this->owner))['data'];
        self::assertSame(20, $capped['nombreParPage']);
    }

    #[DataProvider('badFilters')]
    public function testRejectsUnknownFilterValues(string $query, string $param): void
    {
        $response = $this->request('GET', $this->url($query), null, $this->owner);

        self::assertStatus(400, $response);
        self::assertStringContainsString($param, $this->json($response)['message']);
    }

    public static function badFilters(): iterable
    {
        yield 'statut inconnu' => ['?status=archived', 'status'];
        yield 'période inconnue' => ['?period=soon', 'period'];
        yield 'statut vide' => ['?status=', 'status'];
        yield 'tri inconnu' => ['?sort=title:desc', 'sort'];
        yield 'statut tableau' => ['?status[]=draft', 'status'];
    }

    public function testEventsRequireMembership(): void
    {
        self::assertStatus(401, $this->request('GET', $this->url()));
        self::assertStatus(403, $this->request('GET', $this->url(), null, $this->stranger));
        self::assertStatus(404, $this->request('GET', '/api/organizers/999999/events', null, $this->owner));

        $root = $this->data->user(roles: [User::ROLE_SUPER_ADMIN]);
        self::assertStatus(200, $this->request('GET', $this->url(), null, $root));
    }

    // ------------------------------------------------- Recherche d'utilisateurs

    private function candidates(string $query, ?User $as): \Symfony\Component\HttpFoundation\Response
    {
        return $this->request('GET', '/api/organizers/' . $this->organizer->getId() . '/member-candidates' . $query, null, $as);
    }

    public function testAdminFindsInvitableUsersExcludingCurrentMembers(): void
    {
        $this->data->user('claire.rasoa@exemple.mg');
        $this->data->user('clairvoyant@exemple.mg');
        $already = $this->data->user('claire.membre@exemple.mg');
        $this->data->membership($already, $this->organizer, OrganizerRole::MEMBER);

        $response = $this->candidates('?q=CLAIR', $this->owner);

        self::assertStatus(200, $response);
        $data = $this->json($response)['data'];
        self::assertSame(2, $data['itemsTotal']);
        self::assertEqualsCanonicalizing(
            ['claire.rasoa@exemple.mg', 'clairvoyant@exemple.mg'],
            array_column($data['items'], 'email')
        );
        self::assertSame(['id', 'email', 'firstname', 'lastname'], array_keys($data['items'][0]), 'Champs minimaux : ni téléphone ni rôles.');
    }

    public function testSearchMatchesNamesAndEscapesWildcards(): void
    {
        $user = $this->data->user('quelquun@exemple.mg');
        $user->setFirstname('Voahangy')->setLastname('RAZAFY');
        $this->data->save($user);

        self::assertSame(1, $this->json($this->candidates('?q=voahan', $this->owner))['data']['itemsTotal']);
        self::assertSame(1, $this->json($this->candidates('?q=razafy', $this->owner))['data']['itemsTotal']);
        self::assertSame(0, $this->json($this->candidates('?q=%25%25%25', $this->owner))['data']['itemsTotal'], '« %%% » n’est pas un joker.');
    }

    public function testSearchIsCappedAtTenResults(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->data->user(sprintf('invite%02d@exemple.mg', $i));
        }

        self::assertSame(10, $this->json($this->candidates('?q=invite', $this->owner))['data']['itemsTotal']);
    }

    #[DataProvider('badTerms')]
    public function testRejectsMissingOrShortTerm(string $query): void
    {
        $response = $this->candidates($query, $this->owner);

        self::assertStatus(400, $response);
        self::assertStringContainsString('au moins 3 caractères', $this->json($response)['message']);
    }

    public static function badTerms(): iterable
    {
        yield 'absent' => [''];
        yield 'vide' => ['?q='];
        yield 'trop court' => ['?q=ab'];
        yield 'espaces' => ['?q=%20%20%20%20'];
        yield 'tableau' => ['?q[]=abc'];
    }

    public function testSearchRequiresAdminRole(): void
    {
        self::assertStatus(401, $this->candidates('?q=abcd', null));
        self::assertStatus(403, $this->candidates('?q=abcd', $this->member), 'Un simple membre ne peut pas inviter.');
        self::assertStatus(403, $this->candidates('?q=abcd', $this->stranger));
    }
}
