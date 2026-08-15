# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

TicketUp API — REST API for events/ticketing. Symfony 7.2 + API Platform 4, PHP ≥ 8.2, Doctrine ORM 3 on PostgreSQL 16. Code comments, docs and API response messages are in French; keep that convention.

## Commands

```bash
composer install
symfony server:start -d          # or: php -S localhost:8000 -t public

docker compose up -d database    # Postgres 16 (port is host-assigned, see `docker compose port database 5432`)
                                 # DATABASE_URL lives in .env.local (untracked)

php bin/console lexik:jwt:generate-keypair   # required once; config/jwt/*.pem are gitignored

php bin/console doctrine:migrations:migrate
php bin/console doctrine:migrations:diff     # after changing entities
php bin/console doctrine:fixtures:load       # purge + rejeu complet (voir « Fixtures » plus bas)

php bin/console debug:router
php bin/console debug:api-resource App\\Entity\\Event
php bin/console api:openapi:export           # OpenAPI docs also served at /api/docs
```

There is no test suite, no PHPUnit, and no linter/static-analysis configured in this project. Do not claim tests pass — verify changes by hitting endpoints (e.g. `curl`) instead.

## Architecture

### API Platform is used for routing/OpenAPI, not for its state providers

Almost every operation is declared in the entity's `#[ApiResource]` attribute with `read: false` and delegates to a custom invokable controller under `src/Controller/`. See `src/Entity/Event.php:19` and `src/Entity/Category.php:13`.

Consequence: API Platform's built-in pagination, filters, and normalization contexts **do not apply** to those operations. Pagination is re-implemented by hand in each controller (`page` / `itemsPerPage` query params, clamped, then `findBy` with offset — see `src/Controller/Api/Event/GetEventsController.php`).

To add an endpoint: add an operation to the entity's `#[ApiResource]` (uriTemplate + `controller:` + `read: false`, plus an `OpenApiOperation` if it takes parameters), then write the `#[AsController]` invokable controller. Routes are mounted under the `/api` prefix by `config/routes/api_platform.yaml`.

### Response envelope

Controllers return a uniform shape; keep new endpoints consistent with it:

```json
{ "message": "...", "status": 200, "data": … }
```

For collections, `data` is `{ "itemsTotal", "currentPage", "nombreParPage", "items" }`. Note `GetEventsByCategoryController` predates this convention and returns a bare serialized array — align it if you touch it.

Serialization is done manually via `SerializerInterface` with explicit groups, then `json_decode`d back into the envelope. Groups in use: `events:lists`, `events:details`, `events:create`, `category:lists`, `category:details`.

### Event creation (`POST /api/events`)

The only operation running through API Platform's own pipeline. Deserialization is fully taken over by `src/Serializer/Denormalizer/EventInputDenormalizer.php` (registered with the `serializer.normalizer` tag in `config/services.yaml`), which builds the `Event` field-by-field and either links existing `Category`/`Organizer`/`Location` by `id` or creates them inline from a nested payload; `ticket_type[]` is created and attached (cascade persist from `Event`). Because it replaces denormalization entirely, the `events:create` group on entity properties is documentation-only for this path — changes to the accepted payload go in the denormalizer, not in the groups.

### Fixtures

`src/DataFixtures/` est découpé par entité, avec des dépendances explicites (`DependentFixtureInterface`) et des références nommées : `CategoryFixtures`, `LocationFixtures`, `OrganizerFixtures`, `UserFixtures` → `MembershipFixtures`, `EventFixtures`.

Données fixes (catégories, lieux, organisations, comptes) plutôt que du Faker : des noms tirés au sort — « ullam », « totam » — rendent l'interface illisible et les filtres intestables. Faker n'est utilisé que pour les événements, avec `seed(2026)` : deux chargements donnent le même jeu.

Le jeu couvre volontairement les cas limites : événements passés / en cours / à venir, 5 événements sans billetterie, 5 en sur-réservation (quotas > jauge du lieu), tarifs gratuits, brouillons non publiés. Les dates de fin découlent toujours de la date de début (l'ancienne version tirait les deux indépendamment et produisait des événements terminés avant d'avoir commencé) et les prix sont des entiers en ariary arrondis à 500.

Comptes de démonstration — mot de passe commun `Password123!` (`UserFixtures::PASSWORD`) :

| Compte | Rôle |
|---|---|
| `admin@ticketup.mg` | `ROLE_SUPER_ADMIN`, **membre d'aucune organisation** (accès par le rôle global) |
| `hery.rakoto@techmada.mg` | responsable de **Tech Madagascar** et de **Madagascar Events** |
| `lova.randria@techmada.mg` / `mamy.rabe@techmada.mg` | admin / membre de Tech Madagascar |
| `noro.andria@madagascar-events.mg` | co-responsable de Madagascar Events |

`Sarobidy Événements` et `Vibes Mada` n'ont aucun membre, et `Tana Sound System` a un membre sans responsable : ces états servent à tester l'amorçage par la console.

**`doctrine:fixtures:load` purge la base** (y compris `"user"` et `refresh_tokens`). Pour vérifier un changement sans perdre les données de dev, viser une base jetable :

```bash
DATABASE_URL="…/ticket-up-check?serverVersion=16&charset=utf8" \
  php bin/console doctrine:database:create && \
  php bin/console doctrine:schema:create && \
  php bin/console doctrine:fixtures:load --no-interaction
```

(`doctrine:migrations:migrate` ne suffit pas sur une base vierge : la première migration a un `up()` vide.)

### Organisations et rôles (User ↔ Organizer)

La relation est un **many-to-many porté par une entité** : `OrganizerMembership` (`user`, `organizer`, `role`). Une même personne peut donc diriger plusieurs organisations indépendantes, avec un rôle distinct dans chacune. Il n'existe **pas** de colonne `user_id` sur `organizer` ni l'inverse.

Rôles : enum PHP `App\Enum\OrganizerRole` — `owner` > `admin` > `member`, hiérarchiques (`isAtLeast()`), avec des niveaux espacés de 10 pour insérer des rôles intermédiaires sans renumérotation. Unicité sur (user, organizer) : un rôle par organisation ; passer au cumul de rôles = étendre la contrainte à (user, organizer, role).

`ROLE_SUPER_ADMIN` (dans `user.roles`) est le rôle **global** du fondateur : il court-circuite tous les contrôles d'appartenance (`User::isSuperAdmin()`, testé en premier dans `OrganizerVoter`).

**Toutes les écritures passent par `OrganizerMembershipService`** (`assign`, `changeRole`, `revoke`, `transferOwnership`) : c'est lui qui garantit l'invariant « une organisation garde toujours au moins un responsable ». N'écrivez pas de `OrganizerMembership` directement depuis un contrôleur.

Droits : `OrganizerVoter` (VIEW = member+, EDIT/MANAGE_MEMBERS = admin+, TRANSFER_OWNERSHIP = owner). Les erreurs métier étendent `BusinessException` (`MembershipException` pour les appartenances, `OrganizerException` pour la saisie à la création) : chacune porte son code HTTP et `BusinessExceptionSubscriber` les convertit en réponse enveloppée. Une nouvelle famille d'erreurs métier n'a qu'à étendre la base pour en profiter.

`POST /api/organizers` (`Api\Organizer\CreateOrganizerController`) est la seule façon de créer une organisation **gérable** : il rattache le créateur comme responsable dans la même transaction. Celles créées par le payload imbriqué de `POST /api/events` n'ont aucun membre, donc n'apparaissent dans aucun `/me`.

Endpoints (contrôleurs Symfony classiques sous `src/Controller/Api/Membership/`, hors pipeline API Platform car ce sont des écritures à corps libre — ils n'apparaissent donc pas dans `/api/docs`). Seule exception : `GET /api/organizers/me`, déclaré comme opération de `#[ApiResource]` sur `Organizer` (`read: false`), donc visible dans `/api/docs`.

| Méthode | Route | Droit |
|---|---|---|
| GET | `/api/organizers/me` | authentifié |
| GET | `/api/user/me/organizations` | authentifié |
| GET | `/api/organizers/{id}/members` | VIEW |
| POST | `/api/organizers/{id}/members` | MANAGE_MEMBERS (OWNER si `role=owner`) |
| PATCH/PUT | `/api/organizers/{id}/members/{userId}` | MANAGE_MEMBERS (OWNER si un responsable est concerné) |
| DELETE | `/api/organizers/{id}/members/{userId}` | MANAGE_MEMBERS |
| PUT | `/api/organizers/{id}/owner` | TRANSFER_OWNERSHIP |

`GET /api/events/me` (`Api\Event\GetMyEventsController`) suit la même logique côté événements : un événement n'a pas d'auteur propre, il appartient à une organisation, donc « mes événements » se lit `Event → Organizer → OrganizerMembership → User` (`EventRepository::findByUser()`). Réponse strictement identique à `/api/events` (groupe `events:lists`, même enveloppe, même pagination) ; seul l'ensemble listé change. Pas de filtre sur `status` : c'est une vue de gestion, les brouillons de son organisation restent visibles.

⚠️ `/events/{id}` porte désormais `requirements: ['id' => '\d+']`. Sans cette contrainte il attrape tout ce qui suit `/events/`, et les routes littérales (`/events/me`, `/events/search`) ne tenaient que par leur ordre de déclaration. Toute nouvelle route littérale sous `/events/` en dépend.

`/api/organizers/me` et `/api/user/me/organizations` répondent à la même question et s'appuient sur le même service ; ils diffèrent par la charge utile. Le premier est la vue réduite (`id`, `name`, `role`, `roleLabel`, `isOwner`), paginée, faite pour un sélecteur d'organisation ; le second ajoute les coordonnées, la date d'entrée et le drapeau `isSuperAdmin`, sans pagination. Les deux champs de mise en forme vivent dans `MembershipPresenter` (`organizationSummary()` / `organization()`) : une évolution de la vue se fait là, pas dans les contrôleurs.

Amorçage : une organisation sans membre est inaccessible via l'API (le droit découle de l'appartenance). Utiliser la console :

```bash
php bin/console app:organizer:member list|assign|revoke|transfer|organizations -o <id|nom> -u <id|email> -r owner|admin|member
php bin/console app:user:super-admin <email> [--revoke]
```

### Authentication

JWT (LexikJWTAuthenticationBundle) with refresh tokens (Gesdinet). Two firewalls in `config/packages/security.yaml`:

- `^/api/auth/login$` — `json_login` reading `email` / `password`, Lexik success/failure handlers.
- `^/api` — stateless `jwt: ~`.

Access control only lists `/api/auth/login`, `/api/auth/register` (public) and `/api/user` (authenticated). Everything else under `/api` is reachable anonymously — that is how the public event/category endpoints work; add an `access_control` entry or `#[IsGranted]` when an endpoint must be protected.

Endpoints: `POST /api/auth/login`, `POST /api/auth/register`, `POST /api/auth/refresh` (`refresh_token` param, single-use rotation, 30-day TTL), `GET /api/user/me`. Access token TTL is 15 min.

`JwtLoginSuccessSubscriber` injects `refresh_token` into the login response payload. Registration goes `RegisterController` → `UserRegistrationService` (validates `RegisterDTO`, hashes password, persists, then issues access + refresh token via `JwtSecurityService`) — registration bypasses the login flow and mints tokens directly, so the two paths for creating refresh tokens must stay in sync.

Note `RegisterController` is declared twice: as a `#[Route]` attribute *and* in `config/routes/auth.yaml`. Removing one is safe; changing the path requires changing both.

### Other

- `API_ENDPOINTS.md` is the full endpoint reference (auth, params, response shapes, known rough edges).
- `STRUCTURE_BDD.md` documents the database schema derived from the entities, including the relation/cascade traps.
- `src/sql/main.sql` is an early hand-written schema sketch and does **not** match the Doctrine entities (e.g. `app_user` vs the `"user"` table). The entities are the source of truth — the single migration has an empty `up()`, so the schema is not reproducible from `migrations/`.
- Validation lives on `RegisterDTO` for registration input and on `User` for the entity; both are checked, so constraints exist in two places.
- Branching: work lands on `develop` via `features/*` branches; `main` is the release branch.
- `API_RECHERCHE_EVENTS.md` documents the search endpoints (`/api/events/search`, `/api/events/category/{categoryId}`); its example responses predate the `{message,status,data}` envelope.
