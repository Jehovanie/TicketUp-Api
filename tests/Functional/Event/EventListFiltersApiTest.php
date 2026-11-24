<?php

namespace App\Tests\Functional\Event;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filtres, tri et pagination communs à GET /api/events et GET /api/events/me
 * (et à /api/organizers/{id}/events, couvert à part).
 *
 * Jeu de données — organisation « A » (dont `$member` est membre) :
 *   rock     « Rock en Fête »     publié, +5 j, catégorie Musique
 *   jazz     « jazz club »        publié, +20 j, catégorie Musique
 *   expo     « Expo 100% Mada »   publié, -10 j (passé), catégorie Art
 *   live     « Concert en cours » publié, commencé il y a 1 h
 *   draft    « Brouillon »        brouillon, +8 j
 * Organisation « B » (sans lien avec `$member`) : « Gala B » publié, +6 j.
 */
final class EventListFiltersApiTest extends ApiTestCase
{
    private User $member;
    private User $root;
    private Organizer $orgA;
    private Organizer $orgB;
    private Category $music;
    private Category $art;

    /** @var array<string, Event> */
    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->data->user();
        $this->root = $this->data->user(roles: [User::ROLE_SUPER_ADMIN]);
        $this->orgA = $this->data->organizer('A', $this->member);
        $this->orgB = $this->data->organizer('B', $this->data->user());
        $this->music = $this->data->category();
        $this->art = $this->data->category();

        $this->e['rock'] = $this->make($this->orgA, 'Rock en Fête', '+5 days', $this->music);
        $this->e['jazz'] = $this->make($this->orgA, 'jazz club', '+20 days', $this->music);
        $this->e['expo'] = $this->make($this->orgA, 'Expo 100% Mada', '-10 days', $this->art);
        $this->e['live'] = $this->make($this->orgA, 'Concert en cours', '-1 hour', $this->music, 5);
        $this->e['draft'] = $this->make($this->orgA, 'Brouillon', '+8 days', $this->art, 3, false);
        $this->e['gala'] = $this->make($this->orgB, 'Gala B', '+6 days', $this->art);
    }

    private function make(Organizer $org, string $title, string $startsAt, Category $category, int $hours = 3, bool $published = true): Event
    {
        $event = $this->data->event($org, $published, $startsAt, $hours);
        $event->setTitle($title)->setCategory($category);

        return $this->data->save($event);
    }

    /** Identifiants renvoyés, traduits en clés du jeu de données, dans l'ordre. */
    private function keys(Response $response): array
    {
        $byId = array_map(static fn (Event $e) => $e->getId(), $this->e);
        $byId = array_flip($byId);

        return array_map(static fn (int $id) => $byId[$id], array_column($this->json($response)['data']['items'], 'id'));
    }

    private function all(string $uri, ?User $as): array
    {
        $response = $this->request('GET', $uri, null, $as);
        self::assertStatus(200, $response);

        return $this->keys($response);
    }

    // ----------------------------------------------------- /api/events : visibilité

    public function testPublicListHidesDraftsForAnonymousAndOrdinaryUsers(): void
    {
        $expected = ['gala', 'live', 'expo', 'jazz', 'rock'];

        self::assertSame($expected, $this->all('/api/events', null), 'Anonyme : publiés seulement, plus récent d’abord.');
        self::assertSame($expected, $this->all('/api/events', $this->member), 'Utilisateur ordinaire : idem.');
    }

    public function testSuperAdminKeepsTheManagementView(): void
    {
        self::assertSame(['gala', 'draft', 'live', 'expo', 'jazz', 'rock'], $this->all('/api/events', $this->root));
        self::assertSame(['draft'], $this->all('/api/events?status=draft', $this->root));
    }

    public function testDraftsRequestedWithoutBeingSuperAdminIs403(): void
    {
        self::assertStatus(403, $this->request('GET', '/api/events?status=draft'));
        self::assertStatus(403, $this->request('GET', '/api/events?status=draft', null, $this->member));
        self::assertSame(
            ['gala', 'live', 'expo', 'jazz', 'rock'],
            $this->all('/api/events?status=published', $this->member)
        );
    }

    // --------------------------------------------------------------------- Filtres

    public function testTitleIsPartialCaseInsensitiveAndEscapesWildcards(): void
    {
        self::assertSame(['rock'], $this->all('/api/events?title=ROCK', null));
        self::assertSame(['jazz'], $this->all('/api/events?title=azz%20CLU', null));
        self::assertSame(['expo'], $this->all('/api/events?title=100%25', null), '« % » littéral, pas un joker.');
        self::assertSame([], $this->all('/api/events?title=%25%25', null));
        self::assertCount(5, $this->all('/api/events?title=', null), 'Titre vide = pas de filtre.');
        self::assertCount(5, $this->all('/api/events?title=%20%20', null));
    }

    public function testFiltersByOrganizerAndCategory(): void
    {
        self::assertSame(['gala'], $this->all('/api/events?organizer=' . $this->orgB->getId(), null));
        self::assertSame(['live', 'jazz', 'rock'], $this->all('/api/events?category=' . $this->music->getId(), null));
        self::assertSame([], $this->all('/api/events?organizer=999999', null), 'Organisation inconnue : liste vide.');
        self::assertSame(
            ['live'],
            $this->all(sprintf('/api/events?organizer=%d&category=%d&period=ongoing', $this->orgA->getId(), $this->music->getId()), null),
            'Les filtres se combinent (ET).'
        );
    }

    public function testFiltersByPeriod(): void
    {
        self::assertSame(['gala', 'jazz', 'rock'], $this->all('/api/events?period=upcoming', null));
        self::assertSame(['live'], $this->all('/api/events?period=ongoing', null));
        self::assertSame(['expo'], $this->all('/api/events?period=past', null));
        self::assertSame(['gala', 'draft', 'jazz', 'rock'], $this->all('/api/events?period=upcoming', $this->root));
    }

    public function testDateBoundsAreInclusiveAndEndDateCoversTheWholeDay(): void
    {
        $day = fn (string $rel) => (new \DateTimeImmutable($rel))->format('Y-m-d');

        // `rock` commence dans 5 jours (à l'heure courante) : même jour pour les deux bornes.
        self::assertSame(['rock'], $this->all(sprintf('/api/events?startDate=%1$s&endDate=%1$s', $day('+5 days')), null));
        self::assertSame(['gala', 'rock'], $this->all(sprintf('/api/events?startDate=%s&endDate=%s', $day('+5 days'), $day('+6 days')), null));
        self::assertSame(['jazz'], $this->all('/api/events?startDate=' . $day('+7 days'), null));
        self::assertSame(['expo'], $this->all('/api/events?endDate=' . $day('-5 days'), null));
        self::assertSame([], $this->all('/api/events?startDate=' . $day('+30 days'), null));
    }

    public function testFiltersCombineAndTotalCountsWithFilters(): void
    {
        $response = $this->request('GET', sprintf('/api/events?category=%d&itemsPerPage=1&page=2&sort=startedAt:asc', $this->music->getId()));

        self::assertStatus(200, $response);
        $data = $this->json($response)['data'];
        self::assertSame(3, $data['itemsTotal'], 'Le total tient compte des filtres, pas de la table entière.');
        self::assertSame(2, $data['currentPage']);
        self::assertSame(1, $data['nombreParPage']);
        self::assertSame(['rock'], $this->keys($response), 'live, rock, jazz par date de début : la page 2 est rock.');
    }

    // ------------------------------------------------------------------------ Tri

    public function testSorting(): void
    {
        self::assertSame(['expo', 'live', 'rock', 'jazz'], $this->all('/api/events?sort=startedAt:asc&organizer=' . $this->orgA->getId(), null));
        self::assertSame(['jazz', 'rock', 'live', 'expo'], $this->all('/api/events?sort=startedAt:desc&organizer=' . $this->orgA->getId(), null));
        self::assertSame(
            ['Concert en cours', 'Expo 100% Mada', 'jazz club', 'Rock en Fête'],
            array_column($this->json($this->request('GET', '/api/events?sort=title:asc&organizer=' . $this->orgA->getId()))['data']['items'], 'title'),
        );
        self::assertSame($this->all('/api/events', null), $this->all('/api/events?sort=createdAt:desc', null), 'Défaut = createdAt:desc.');
    }

    // ------------------------------------------------------------------ Validation

    #[DataProvider('invalidQueries')]
    public function testInvalidParametersAreRejectedWith400(string $query, string $param): void
    {
        foreach (['/api/events', '/api/events/me'] as $path) {
            $response = $this->request('GET', $path . $query, null, $this->member);

            self::assertStatus(400, $response);
            self::assertStringContainsString($param, $this->json($response)['message'], $path);
        }
    }

    public static function invalidQueries(): iterable
    {
        yield 'status inconnu' => ['?status=archived', 'status'];
        yield 'status vide' => ['?status=', 'status'];
        yield 'status tableau' => ['?status[]=draft', 'status'];
        yield 'period inconnue' => ['?period=soon', 'period'];
        yield 'sort inconnu' => ['?sort=price:asc', 'sort'];
        yield 'sort sans sens' => ['?sort=title', 'sort'];
        yield 'startDate mal formée' => ['?startDate=demain', 'startDate'];
        yield 'startDate impossible' => ['?startDate=2026-02-31', 'startDate'];
        yield 'endDate heure' => ['?endDate=2026-05-01T10:00', 'endDate'];
        yield 'dates inversées' => ['?startDate=2026-05-02&endDate=2026-05-01', 'précéder'];
        yield 'organizer texte' => ['?organizer=abc', 'organizer'];
        yield 'organizer négatif' => ['?organizer=-1', 'organizer'];
        yield 'organizer zéro' => ['?organizer=0', 'organizer'];
        yield 'category texte' => ['?category=x', 'category'];
        yield 'title tableau' => ['?title[]=a', 'title'];
    }

    // ----------------------------------------------------------------- /api/events/me

    public function testMyEventsAreScopedToMyOrganizationsDraftsIncluded(): void
    {
        self::assertSame(['draft', 'live', 'expo', 'jazz', 'rock'], $this->all('/api/events/me', $this->member));
        self::assertSame(['draft'], $this->all('/api/events/me?status=draft', $this->member));
        self::assertSame(['live', 'expo', 'jazz', 'rock'], $this->all('/api/events/me?status=published', $this->member));
    }

    public function testMyEventsAcceptTheSameFilters(): void
    {
        self::assertSame(['live', 'jazz', 'rock'], $this->all('/api/events/me?category=' . $this->music->getId(), $this->member));
        self::assertSame(['rock'], $this->all('/api/events/me?title=rock&period=upcoming', $this->member));
        self::assertSame(['expo', 'live', 'rock', 'draft', 'jazz'], $this->all('/api/events/me?sort=startedAt:asc', $this->member));
        self::assertSame(['jazz'], $this->all('/api/events/me?organizer=' . $this->orgA->getId() . '&startDate=' . (new \DateTimeImmutable('+20 days'))->format('Y-m-d'), $this->member));
    }

    public function testMyEventsTotalCountsWithFilters(): void
    {
        $data = $this->json($this->request('GET', '/api/events/me?status=published&itemsPerPage=2', null, $this->member))['data'];

        self::assertSame(4, $data['itemsTotal']);
        self::assertCount(2, $data['items']);
    }

    public function testMyEventsOrganizerOutsideMyOrganizationsIs403(): void
    {
        $response = $this->request('GET', '/api/events/me?organizer=' . $this->orgB->getId(), null, $this->member);

        self::assertStatus(403, $response);
        self::assertStringContainsString('vos organisations', $this->json($response)['message']);
        self::assertStatus(403, $this->request('GET', '/api/events/me?organizer=999999', null, $this->member));
    }

    public function testMyEventsRequireAuthentication(): void
    {
        self::assertStatus(401, $this->request('GET', '/api/events/me'));
    }

    public function testOrganizerEventsReuseTheSameFilters(): void
    {
        $uri = fn (string $q) => sprintf('/api/organizers/%d/events%s', $this->orgA->getId(), $q);

        self::assertSame(['rock'], $this->all($uri('?title=rock'), $this->member));
        self::assertSame(['live', 'jazz', 'rock'], $this->all($uri('?category=' . $this->music->getId()), $this->member));
        self::assertSame(
            ['draft', 'live', 'expo', 'jazz', 'rock'],
            $this->all($uri('?organizer=' . $this->orgB->getId()), $this->member),
            'L’organisation de l’URL l’emporte sur ?organizer=.'
        );
    }
}
