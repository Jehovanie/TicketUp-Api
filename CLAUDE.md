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
php bin/console doctrine:fixtures:load       # Faker-generated events/categories/organizers/locations/tickets

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
