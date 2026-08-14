# Référence des endpoints de l'API TicketUp

Liste complète des routes exposées par l'API, leur fonctionnement interne et leur format de réponse.
Liste vérifiable à tout moment avec :

```bash
php bin/console debug:router
```

Toutes les routes métier sont préfixées par `/api` (préfixe posé par `config/routes/api_platform.yaml`).

## Tableau récapitulatif

| Méthode | Chemin | Auth | Rôle | Contrôleur |
|---|---|---|---|---|
| POST | `/api/auth/register` | publique | Créer un compte + délivrer les tokens | `Auth\RegisterController` |
| POST | `/api/auth/login` | publique | Authentifier (email/mot de passe) | firewall `json_login` |
| POST | `/api/auth/refresh` | publique | Renouveler l'access token | bundle Gesdinet |
| GET | `/api/user/me` | **JWT requis** | Profil de l'utilisateur connecté | `Api\MeController` |
| GET | `/api/events` | anonyme | Liste paginée des événements | `Api\Event\GetEventsController` |
| GET | `/api/events/search` | anonyme | Recherche multi-critères | `Api\Event\SearchEventController` |
| GET | `/api/events/category/{categoryId}` | anonyme | Événements publiés d'une catégorie | `Api\Event\GetEventsByCategoryController` |
| GET | `/api/events/{id}` | anonyme | Détail d'un événement | `Api\Event\GetEventByIdController` |
| POST | `/api/events` | anonyme | Créer un événement (+ relations imbriquées) | pipeline API Platform |
| GET | `/api/admin/events/{id}` | anonyme | Détail « admin » d'un événement | `Admin\Event\AdminEventDetailsController` |
| GET | `/api/categories` | anonyme | Liste paginée des catégories | `Api\Category\GetCategoriesController` |
| GET | `/api/categories/{id}` | anonyme | Détail d'une catégorie | provider API Platform |
| GET | `/api/locations` | anonyme | Liste des lieux | provider API Platform |
| GET | `/api/location/{id}` | anonyme | Détail d'un lieu | provider API Platform |
| GET | `/api/organizers` | anonyme | Liste des organisateurs | provider API Platform |
| GET | `/api/organizer/{id}` | anonyme | Détail d'un organisateur | provider API Platform |
| GET | `/api/docs` | anonyme | Documentation OpenAPI / Swagger UI | API Platform |

> ⚠️ **« anonyme » n'est pas un oubli de documentation, c'est l'état réel.** `config/packages/security.yaml` ne protège explicitement que `/api/user`. Le firewall `^/api` est en `jwt: ~` sans `access_control` associé, donc toutes les autres routes — y compris `POST /api/events` et `/api/admin/events/{id}` — sont accessibles **sans token**.

## Deux formats de réponse coexistent

C'est le point le plus déroutant de cette API : selon l'endpoint, la réponse n'a pas la même forme.

**1. Enveloppe maison** (endpoints à contrôleur personnalisé) :

```json
{
  "message": "Liste des événements récupérée avec succès",
  "status": 200,
  "data": { "itemsTotal": 42, "currentPage": 1, "nombreParPage": 10, "items": [ … ] }
}
```

**2. Format natif API Platform** (JSON-LD / Hydra, endpoints sans contrôleur personnalisé : `/api/categories/{id}`, `/api/locations`, `/api/location/{id}`, `/api/organizers`, `/api/organizer/{id}`, `POST /api/events`) :

```json
{ "@context": "/api/contexts/Location", "@id": "/api/locations", "@type": "Collection", "member": [ … ] }
```

Un client peut demander du JSON simple via l'en-tête `Accept: application/json` (formats activés : `jsonld`, `json`, `jsonhal`).

---

## Authentification

### `POST /api/auth/register`

Créer un compte. Route déclarée à la fois par l'attribut `#[Route]` de `RegisterController` et par `config/routes/auth.yaml` (même nom `api_register`, la seconde déclaration écrase la première).

**Corps :**

```json
{
  "email": "jean@example.com",
  "password": "motdepasse",
  "firstname": "Jean",
  "lastname": "Dupont",
  "phone": "0341234567",
  "language": "fr"
}
```

`email`, `password` (4 à 72 caractères), `firstname` et `lastname` sont obligatoires ; `phone` et `language` sont facultatifs.

**Traitement** (`UserRegistrationService::execute()`) : construction d'un `RegisterDTO` → validation → hachage du mot de passe → persistance → génération d'un access token **et** d'un refresh token (`JwtSecurityService`). L'inscription connecte donc directement l'utilisateur, sans passer par `/api/auth/login`.

**Réponse `201` :**

```json
{
  "token": "eyJ0eXAi…",
  "refresh_token": "a1b2c3…",
  "user": { "id": 1, "email": "jean@example.com", "firstname": "Jean", "lastname": "Dupont", "phone": "0341234567", "language": "fr" }
}
```

**Erreurs :** en cas de données invalides, le service lève une `\InvalidArgumentException` non interceptée → réponse `500`, et non `400`/`422`. Un email déjà utilisé remonte comme une violation de contrainte d'unicité en base, pas comme une erreur de validation propre (la contrainte `#[UniqueEntity]` porte sur `User`, pas sur `RegisterDTO`).

### `POST /api/auth/login`

Géré entièrement par le firewall `login` (`json_login`), pas par un contrôleur applicatif. Les champs lus sont `email` et `password` (`username_path: email`).

```json
{ "email": "jean@example.com", "password": "motdepasse" }
```

**Réponse `200` :** `{ "token": "…", "refresh_token": "…" }`.
Le `token` vient du handler Lexik ; le `refresh_token` est ajouté par `JwtLoginSuccessSubscriber`, qui crée et enregistre le refresh token à chaque connexion réussie.

**Erreur `401` :** `{ "code": 401, "message": "Invalid credentials." }`

### `POST /api/auth/refresh`

Fourni par `gesdinet/jwt-refresh-token-bundle` (`config/routes/gesdinet_jwt_refresh_token.yaml`).

```json
{ "refresh_token": "a1b2c3…" }
```

Renvoie un nouveau couple `token` / `refresh_token`. La configuration est en `single_use: true` : **le refresh token est consommé à chaque appel** et remplacé par un nouveau. Durée de vie : 30 jours (access token : 15 minutes).

### `GET /api/user/me`

Seul endpoint réellement protégé (`#[IsGranted('ROLE_USER')]` + règle `access_control` sur `^/api/user`). Nécessite l'en-tête :

```
Authorization: Bearer <token>
```

Renvoie un objet plat construit à la main dans `MeController` — **sans enveloppe `message/status/data`** :

```json
{
  "id": 1, "email": "jean@example.com", "firstname": "Jean", "lastname": "Dupont",
  "phone": "0341234567", "language": "fr", "roles": ["ROLE_USER"],
  "createdAt": "2026-01-08 16:42:08", "updatedAt": "2026-01-08 16:42:08"
}
```

---

## Événements

### `GET /api/events`

Liste paginée, triée par `createdAt` décroissant.

| Paramètre | Défaut | Bornes |
|---|---|---|
| `page` | 1 | ≥ 1 |
| `itemsPerPage` | 10 | 1 à 20 |

La pagination est calculée à la main dans le contrôleur (`count()` + `findBy()` avec `limit`/`offset`) : les réglages `paginationItemsPerPage` de l'attribut `#[ApiResource]` ne s'appliquent pas, l'opération étant en `read: false`.

⚠️ **Cet endpoint ne filtre pas sur `status`** : les événements non publiés (`status = false`, valeur par défaut à la création) apparaissent dans la liste, contrairement à la recherche et au filtre par catégorie.

Groupe de sérialisation : `events:lists` → `id`, `title`, `startedAt`, `endAt`, `status`, `category`, `location`, `ticket_type`.

### `GET /api/events/search`

Recherche multi-critères. Documenté en détail dans `API_RECHERCHE_EVENTS.md`.

| Paramètre | Type | Effet (`EventRepository::searchEvents()`) |
|---|---|---|
| `category` | int | `e.category = :categoryId` |
| `title` | string | `e.title LIKE %…%` |
| `startDate` | date | `e.startedAt >= :startDate` |
| `endDate` | date | `e.startedAt <= :endDate` |
| `page` / `itemsPerPage` | int | pagination (défaut 1 / 10, max 20) |

Les critères se combinent en `AND`, et `status = true` est toujours imposé. **Au moins un critère est requis**, sinon `400`.

Les dates passent par `new \DateTimeImmutable($valeur)` : le format `Y-m-d` est celui documenté, mais toute chaîne comprise par PHP est acceptée ; une chaîne invalide renvoie `400`.

⚠️ La pagination s'applique **après** la requête SQL (`array_slice` sur le résultat complet) : toutes les lignes correspondantes sont chargées en mémoire à chaque appel.

⚠️ `LIKE` est sensible à la casse sur PostgreSQL — la recherche par titre ne l'est donc pas réellement, contrairement à ce qu'annonce `API_RECHERCHE_EVENTS.md` (`ILIKE` ou une comparaison en minuscules serait nécessaire).

### `GET /api/events/category/{categoryId}`

Tous les événements publiés (`status = true`) d'une catégorie, triés par `startedAt` décroissant. Pas de pagination.

⚠️ **Seul endpoint qui ne suit pas l'enveloppe maison** : il renvoie directement le tableau JSON sérialisé (groupe `events:lists`). Une catégorie inexistante renvoie `[]` et non `404`.

### `GET /api/events/{id}`

Détail d'un événement, groupes `events:lists` + `events:details` (ajoute `description`, `organizer`, les timestamps des billets…). Renvoie `404` avec l'enveloppe (`{"message": "Événement non trouvé", "status": 404, "data": null}`) si l'identifiant n'existe pas.

### `POST /api/events`

**Seule opération passant par le pipeline API Platform**, mais sa désérialisation est intégralement reprise par `EventInputDenormalizer` (`src/Serializer/Denormalizer/EventInputDenormalizer.php`).

```json
{
  "title": "Concert Rock",
  "description": "…",
  "startedAt": "2026-02-15T20:00:00+00:00",
  "endAt": "2026-02-15T23:00:00+00:00",
  "category":  { "id": 1 },
  "organizer": { "name": "Prod SARL", "email": "contact@prod.mg", "phone": null, "website": null },
  "location":  { "name": "Palais des Sports", "size": 5000 },
  "ticket_type": [ { "name": "VIP", "prix": 50000, "quantite_max": 100 } ]
}
```

Pour `category`, `organizer` et `location`, deux modes coexistent :

- **`{"id": X}`** → l'entité existante est récupérée ; si elle est introuvable, une `\RuntimeException` est levée (« Catégorie introuvable (id: X) ») ;
- **objet sans `id`** → une nouvelle entité est créée à la volée et persistée en cascade (`cascade: ['persist']` sur les relations de `Event`).

Les `ticket_type[]` sont créés et rattachés à l'événement (`cascade: ['persist', 'remove']`).

Points de vigilance :
- l'événement est créé avec **`status = false`** (constructeur de `Event`) : il n'apparaît donc ni dans la recherche ni dans le filtre par catégorie tant qu'il n'est pas publié, et aucun endpoint ne permet aujourd'hui de basculer ce statut ;
- le groupe `events:create` visible sur les propriétés de l'entité est purement documentaire pour cette opération, puisque le denormalizer construit l'objet champ par champ — **toute modification du payload accepté se fait dans le denormalizer** ;
- `startedAt` et `endAt` sont obligatoires de fait (`new \DateTimeImmutable($data['startedAt'])` échoue si la clé est absente) ;
- `imageUrl` n'est pas alimenté par cet endpoint ;
- aucun `normalizationContext` n'est défini sur l'opération : la réponse est la représentation par défaut d'API Platform, différente de celle des endpoints de lecture.

### `GET /api/admin/events/{id}`

Endpoint de détail « administrateur » (`AdminEventDetailsController`), renvoyant un état des billets :

```json
{
  "hello": "world",
  "events": {
    "event": { "id": 1, "title": "…", "description": "…" },
    "statusTicket": {
      "global": [ { "VIP": 100 } ],
      "actuel": [ { "VIP": 100 } ],
      "filter": { "time": "2026-08-13 10:00:00", "value": [ { "VIP": 100 } ] }
    }
  }
}
```

⚠️ Endpoint manifestement en cours de développement : la clé `"hello": "world"` est un reliquat, les trois sections `global` / `actuel` / `filter` contiennent la **même valeur** (aucune table de billets vendus n'existe en base, cf. `STRUCTURE_BDD.md`), aucun contrôle de rôle n'est appliqué malgré le préfixe `/admin`, et un identifiant inexistant provoque une erreur `500` (appel de méthode sur `null`) au lieu d'un `404`.

---

## Catégories, lieux, organisateurs

### `GET /api/categories`

Liste paginée triée par `name` croissant, enveloppe maison, groupe `category:lists` (`id`, `name`, `color`).

| Paramètre | Défaut | Bornes |
|---|---|---|
| `page` | 1 | ≥ 1 |
| `itemsPerPage` | 20 | 1 à 50 |

Noter que les bornes diffèrent de celles des événements (10/20).

### `GET /api/categories/{id}`

Passe par le provider natif d'API Platform (pas de contrôleur) : réponse **JSON-LD**, groupes `category:lists` + `category:details` (ajoute `createdAt` / `updatedAt`). `404` géré par API Platform.

### `GET /api/locations` · `GET /api/location/{id}`

Providers natifs d'API Platform, réponse JSON-LD paginée. Groupes `location:lists` (`id`, `name`, `size`) et, sur le détail, `location:details` (timestamps).

⚠️ Incohérence d'URL héritée des `uriTemplate` par défaut : la collection est au **pluriel** (`/api/locations`), le détail au **singulier** (`/api/location/{id}`).

### `GET /api/organizers` · `GET /api/organizer/{id}`

Même fonctionnement. Groupes `organizer:lists` (`id`, `name`, `email`) et `organizer:details` (`phone`, `website`, timestamps). Même incohérence pluriel/singulier.

Il n'existe **aucun endpoint d'écriture** pour les catégories, lieux et organisateurs : ils ne peuvent être créés qu'indirectement, via le payload imbriqué de `POST /api/events`.

---

## Routes techniques

| Chemin | Rôle |
|---|---|
| `/api/docs` | Documentation OpenAPI (Swagger UI en HTML, JSON avec `Accept: application/json`) |
| `/api` | Point d'entrée Hydra listant les ressources |
| `/api/contexts/{shortName}` | Contextes JSON-LD |
| `/api/errors/{status}`, `/api/validation_errors/{id}` | Représentation des erreurs API Platform |
| `/_error/{code}` | Prévisualisation des pages d'erreur (environnement `dev` uniquement) |

La documentation OpenAPI peut aussi être exportée :

```bash
php bin/console api:openapi:export
```

---

## CORS

`config/packages/nelmio_cors.yaml` autorise, pour `^/api/`, **toutes les origines** (`allow_origin: ['*']`) avec `allow_credentials: true`, les méthodes `GET, POST, PUT, DELETE, OPTIONS` et les en-têtes `Content-Type` / `Authorization`. La variable `CORS_ALLOW_ORIGIN` présente dans `.env` n'est pas utilisée par cette configuration.

## Exemples cURL

```bash
# Inscription
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"jean@example.com","password":"motdepasse","firstname":"Jean","lastname":"Dupont"}'

# Connexion
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"jean@example.com","password":"motdepasse"}' | jq -r .token)

# Profil
curl http://localhost:8000/api/user/me -H "Authorization: Bearer $TOKEN"

# Liste et recherche
curl "http://localhost:8000/api/events?page=1&itemsPerPage=10"
curl "http://localhost:8000/api/events/search?category=1&title=concert&startDate=2026-01-01"
curl "http://localhost:8000/api/events/category/1"

# Ressources natives API Platform (JSON simple plutôt que JSON-LD)
curl http://localhost:8000/api/locations -H "Accept: application/json"
```

## Documents liés

- `STRUCTURE_BDD.md` — schéma de la base de données
- `API_RECHERCHE_EVENTS.md` — détail des endpoints de recherche
- `CLAUDE.md` — architecture et conventions du projet
