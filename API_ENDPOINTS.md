# Référence des endpoints de l'API TicketUp

> **Dernière mise à jour : 23 octobre 2026.**
> Inventaire repris route par route depuis `php bin/console debug:router` et le code des contrôleurs : **26 endpoints métier**, auxquels s'ajoutent les routes techniques d'API Platform.
> Ajouts de cette révision : `GET /api/health`, `GET /api/user/me/organizations` et les cinq endpoints de gestion des membres, qui existaient dans le code sans être documentés — ici **et** dans `/api/docs`, où ils n'apparaissaient pas non plus.

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
| GET | `/api/health` | anonyme | Sonde de disponibilité du service | `Api\MeController::healthCheck()` |
| GET | `/api/user/me` | **JWT requis** | Profil de l'utilisateur connecté | `Api\MeController` |
| GET | `/api/user/me/organizations` | **authentifié** | Mes organisations, vue complète + `isSuperAdmin` | `Api\Membership\GetMyOrganizationsController` |
| GET | `/api/events` | anonyme | Liste paginée des événements | `Api\Event\GetEventsController` |
| GET | `/api/events/me` | **authentifié** | Événements de mes organisations | `Api\Event\GetMyEventsController` |
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
| POST | `/api/organizers` | **authentifié** | Créer une organisation (créateur = responsable) | `Api\Organizer\CreateOrganizerController` |
| GET | `/api/organizers/me` | **authentifié** | Mes organisations, vue réduite + rôle | `Api\Membership\GetMyOrganizersController` |
| GET | `/api/organizer/{id}` | anonyme | Détail d'un organisateur | provider API Platform |
| GET | `/api/organizers/{organizerId}/members` | **VIEW** | Membres d'une organisation | `Api\Membership\GetOrganizerMembersController` |
| POST | `/api/organizers/{organizerId}/members` | **MANAGE_MEMBERS** | Rattacher un utilisateur (OWNER si `role=owner`) | `Api\Membership\AddOrganizerMemberController` |
| PATCH · PUT | `/api/organizers/{organizerId}/members/{userId}` | **MANAGE_MEMBERS** | Changer le rôle d'un membre | `Api\Membership\UpdateOrganizerMemberRoleController` |
| DELETE | `/api/organizers/{organizerId}/members/{userId}` | **MANAGE_MEMBERS** | Retirer un membre | `Api\Membership\RemoveOrganizerMemberController` |
| PUT | `/api/organizers/{organizerId}/owner` | **TRANSFER_OWNERSHIP** | Désigner un nouveau responsable | `Api\Membership\TransferOrganizerOwnershipController` |
| GET | `/api/docs` | anonyme | Documentation OpenAPI / Swagger UI | API Platform |

> ⚠️ **« anonyme » n'est pas un oubli de documentation, c'est l'état réel.** `config/packages/security.yaml` ne protège explicitement que `/api/user`. Le firewall `^/api` est en `jwt: ~` sans `access_control` associé, donc toutes les routes non listées ci-dessous sont accessibles **sans token** — y compris `POST /api/events` et `/api/admin/events/{id}`.
>
> La protection réelle ne vient donc pas d'`access_control` mais des attributs posés dans les contrôleurs. Les seuls endpoints qui exigent un jeton sont : `GET /api/user/me`, `GET /api/user/me/organizations`, `GET /api/events/me`, `GET /api/organizers/me`, `POST /api/organizers` (tous en `#[IsGranted('ROLE_USER')]`) et les cinq endpoints de membres, qui ajoutent un contrôle de droit par `OrganizerVoter`. Deux conséquences : l'inventaire des endpoints protégés se lit dans le code, pas dans la configuration ; et un `access_control` oublié sur une nouvelle route la laisse publique par défaut.

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

## Santé du service

### `GET /api/health`

Sonde de disponibilité, déclarée par un `#[Route]` dans `MeController` (à côté de `/api/user/me`, dont elle ne partage ni la logique ni les protections). Accessible **sans token**.

```json
{ "message": "Service is up and running", "status": "OK" }
```

⚠️ Deux réserves pour un usage en supervision :
- `status` vaut la **chaîne** `"OK"`, et non un entier comme dans l'enveloppe maison du reste de l'API — un client qui teste `status === 200` échouera ;
- la sonde ne vérifie **rien** : ni la base de données, ni les clés JWT. Elle répond `200` dès que PHP et le noyau Symfony démarrent, donc une base injoignable la laisse verte. Une sonde utile pour un orchestrateur demanderait un `SELECT 1` et un code `503` en cas d'échec.

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

Seul endpoint protégé **à la fois** par un attribut (`#[IsGranted('ROLE_USER')]`) et par une règle `access_control` (`^/api/user`) ; les autres endpoints authentifiés ne tiennent que par leur attribut. Nécessite l'en-tête :

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

### `GET /api/events/me`

**Authentification requise** (`Authorization: Bearer <token>`).

Réponse **strictement identique** à `/api/events` — mêmes champs (`events:lists`), même enveloppe, mêmes paramètres `page` / `itemsPerPage`, même tri `createdAt` décroissant. Seul l'ensemble listé change : les événements portés par les organisations dont l'appelant est membre.

Le lien est indirect. Un événement n'a pas d'auteur : il appartient à une organisation (`event.organizer_id`, non nul), et c'est `OrganizerMembership` qui rattache la personne à cette organisation. `EventRepository::findByUser()` traverse donc `Event → Organizer → OrganizerMembership → User`. L'unicité (utilisateur, organisation) garantit une seule ligne d'appartenance par organisation : aucun doublon d'événement, pas de `DISTINCT`.

Conséquences pratiques :

- Le rôle (`owner`, `admin`, `member`) **ne filtre rien** : tout membre voit les événements de son organisation. Restreindre par rôle demanderait un `andWhere` sur `m.role`.
- Une personne membre de plusieurs organisations reçoit l'**union** de leurs événements, sans distinction d'origine — `organizer` appartient au groupe `events:details`, absent de cette liste.
- **Pas de filtre sur `status`**, comme `/api/events` : les brouillons de son organisation restent visibles, ce qui est le but d'une vue de gestion.
- `ROLE_SUPER_ADMIN` n'y change rien : ce rôle global ouvre les droits mais ne crée pas d'appartenance, donc un super administrateur membre de rien reçoit `items: []`. L'inventaire complet, c'est `/api/events`.

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

L'opération porte `requirements: ['id' => '\d+']`. Sans cette contrainte, `/events/{id}` attrape tout ce qui suit `/events/` et les routes littérales (`/events/me`, `/events/search`) ne tiennent que par leur ordre de déclaration. Effet de bord : un identifiant non numérique (`/api/events/abc`) donne un `404` de routage — page d'erreur Symfony, sans l'enveloppe — au lieu du `404` enveloppé du contrôleur.

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

Il n'existe **aucun endpoint d'écriture** pour les catégories et les lieux : ils ne peuvent être créés qu'indirectement, via le payload imbriqué de `POST /api/events`.

### `POST /api/organizers`

**Authentification requise.** Seule façon de créer une organisation *gérable* : le créateur est rattaché comme **responsable** dans la foulée. Créée par le payload imbriqué de `POST /api/events`, elle n'a aucun membre — donc elle n'apparaît dans aucun `/me` et personne n'a de droits dessus.

```json
{ "name": "Antsika Prod", "email": "contact@antsika.mg", "phone": "+261 34 …", "website": "https://antsika.mg" }
```

`name` et `email` sont obligatoires ; `phone` et `website` facultatifs. L'email est normalisé en minuscules. Réponse `201`, `data` au format « organisation d'un utilisateur » — identique aux `items` de `/api/user/me/organizations`, `role` compris.

L'entité ne portant aucune contrainte de validation, elles sont faites par le contrôleur (`OrganizerException`, `400`) : champ vide, longueur au-delà de la colonne, email ou URL malformés. La création est transactionnelle — un échec du rattachement ne laisse pas d'organisation sans responsable.

⚠️ La colonne `email` **n'est pas unique** en base : deux organisations peuvent partager une adresse, et l'endpoint ne l'interdit pas.

### `GET /api/organizers/me`

**Authentification requise** (`Authorization: Bearer <token>`) — c'est, avec `/api/user`, l'un des rares endpoints réellement protégés.

Organisations auxquelles l'utilisateur connecté appartient, avec son rôle dans chacune. Contrairement à `/api/organizers`, qui liste tout le monde en JSON-LD, celui-ci passe par un contrôleur (`Api\Membership\GetMyOrganizersController`) : enveloppe maison, pagination manuelle (`page`, `itemsPerPage`, plafonné à 20), et vue réduite — pas de coordonnées ni de timestamps.

```json
{
  "message": "Organisations de l’utilisateur récupérées avec succès",
  "status": 200,
  "data": {
    "itemsTotal": 2,
    "currentPage": 1,
    "nombreParPage": 10,
    "items": [
      { "id": 2, "name": "Madagascar Events", "role": "owner", "roleLabel": "Responsable", "isOwner": true },
      { "id": 1, "name": "Tech Madagascar", "role": "owner", "roleLabel": "Responsable", "isOwner": true }
    ]
  }
}
```

Triées par nom d'organisation. `ROLE_SUPER_ADMIN` ne change rien ici : ce rôle global ouvre tous les droits mais ne crée aucune appartenance, donc un super administrateur membre d'aucune organisation reçoit `items: []`.

Pour la même liste avec les coordonnées, la date d'entrée et le drapeau `isSuperAdmin`, voir `GET /api/user/me/organizations`.

### `GET /api/user/me/organizations`

**Authentification requise.** Même question que `/api/organizers/me`, charge utile différente : la fiche complète de chaque organisation (coordonnées, date d'entrée) et le drapeau `isSuperAdmin`, **sans pagination**.

Le choix entre les deux est donc fonctionnel : `/api/organizers/me` pour un sélecteur d'organisation (vue réduite, paginée), celui-ci pour un écran « mes organisations » qui affiche les coordonnées. Les deux lisent le même service (`OrganizerMembershipService::membershipsOf()`) et le même formateur (`MembershipPresenter`) : faire évoluer une vue se fait dans `organizationSummary()` / `organization()`, pas dans les contrôleurs.

```json
{
  "message": "Organisations de l’utilisateur récupérées avec succès",
  "status": 200,
  "data": {
    "itemsTotal": 2,
    "isSuperAdmin": false,
    "items": [
      {
        "id": 2, "name": "Madagascar Events", "email": "contact@madagascar-events.mg",
        "phone": "+261 34 …", "website": "https://madagascar-events.mg",
        "role": "owner", "roleLabel": "Responsable", "isOwner": true,
        "joinedAt": "2026-03-04T09:12:00+00:00"
      }
    ]
  }
}
```

Noter que `data` ne porte **ni `currentPage` ni `nombreParPage`** ici : la forme de l'enveloppe collection n'est pas tout à fait celle du reste de l'API. Triées par nom d'organisation.

---

## Membres d'une organisation

Cinq endpoints, écrits en contrôleurs Symfony classiques (`src/Controller/Api/Membership/`) et **hors du pipeline API Platform** : ce sont des écritures à corps libre, qui ne correspondent à aucune ressource. Ils sont malgré tout décrits dans `/api/docs`, par le décorateur `src/OpenApi/UndocumentedEndpointsFactory.php` (voir « D'où vient `/api/docs` » plus bas).

La relation utilisateur ↔ organisation est un many-to-many porté par `OrganizerMembership` (`user`, `organizer`, `role`), avec unicité sur (user, organizer) : **un rôle par organisation**, et une même personne peut diriger plusieurs organisations indépendantes.

**Rôles** (`App\Enum\OrganizerRole`), hiérarchiques :

| Valeur | `roleLabel` | Niveau | Peut |
|---|---|---|---|
| `owner` | Responsable | 30 | tout, y compris désigner un responsable |
| `admin` | Administrateur | 20 | gérer l'organisation et ses membres |
| `member` | Membre | 10 | consulter |

Les contrôles s'expriment en « au moins ce niveau » (`isAtLeast()`). La saisie est tolérante : `OWNER`, `owner`, ` Owner ` sont acceptés (`fromInput()`).

**Droits** (`OrganizerVoter`) : `VIEW` = member+, `EDIT` / `MANAGE_MEMBERS` = admin+, `TRANSFER_OWNERSHIP` = owner. `ROLE_SUPER_ADMIN` court-circuite tous ces contrôles (`User::isSuperAdmin()`, testé en premier) — mais **ne crée aucune appartenance** : un super administrateur membre de rien reçoit `items: []` sur les endpoints en `/me`.

**Toutes les écritures passent par `OrganizerMembershipService`**, qui tient l'invariant « une organisation garde toujours au moins un responsable ». N'écrivez pas de `OrganizerMembership` depuis un contrôleur.

### `GET /api/organizers/{organizerId}/members`

Droit **VIEW** (member+). Membres de l'organisation, **responsables en tête** (tri par niveau de rôle décroissant, puis par nom de famille). Pas de pagination.

```json
{
  "message": "Membres de l’organisation récupérés avec succès",
  "status": 200,
  "data": {
    "organizer": { "id": 1, "name": "Tech Madagascar" },
    "itemsTotal": 3,
    "items": [
      {
        "userId": 2, "email": "hery.rakoto@techmada.mg", "firstname": "Hery", "lastname": "Rakoto",
        "phone": "+261 34 …", "role": "owner", "roleLabel": "Responsable", "isOwner": true,
        "joinedAt": "2026-03-04T09:12:00+00:00"
      }
    ]
  }
}
```

Le tri par rôle est fait **en PHP** (`usort` après la requête) et non en SQL : `role` est stocké en chaîne, son ordre alphabétique (`admin` < `member` < `owner`) ne correspond pas à la hiérarchie. Sans pagination l'effet est négligeable ; il faudrait une colonne de niveau pour trier en base.

### `POST /api/organizers/{organizerId}/members`

Droit **MANAGE_MEMBERS** (admin+), et **TRANSFER_OWNERSHIP** (owner) si le `role` demandé est `owner` : seul un responsable peut en désigner un autre.

```json
{ "userId": 3, "role": "admin" }
```

L'utilisateur se désigne par `userId` **ou** par `email` (au choix de l'appelant) ; `role` est facultatif et vaut `member` par défaut. L'opération est **idempotente** : rejouer l'appel sur un membre existant met simplement son rôle à jour (toujours `201`, jamais de doublon — l'unicité (user, organizer) l'interdit).

Réponse `201`, `data` au format « membre » (identique aux `items` ci-dessus).

### `PATCH` · `PUT /api/organizers/{organizerId}/members/{userId}`

Droit **MANAGE_MEMBERS**, plus **TRANSFER_OWNERSHIP** dès qu'un responsable est concerné — en promotion (`role: "owner"`) comme en rétrogradation (la cible est déjà responsable).

```json
{ "role": "member" }
```

`role` est ici **obligatoire** (absent → `400`, « Rôle « » inconnu »), contrairement au `POST`. Rétrograder le **dernier** responsable renvoie `409` : il faut d'abord en désigner un autre. Un rôle identique à l'actuel est accepté sans écriture.

⚠️ `PATCH` n'est pas dans `allow_methods` de `nelmio_cors.yaml` : depuis un navigateur, la préflight échoue et seul `PUT` fonctionne. Les deux verbes sont pourtant acceptés par la route — à utiliser en `PUT` côté client web, ou à corriger dans la configuration CORS.

### `DELETE /api/organizers/{organizerId}/members/{userId}`

Droit **MANAGE_MEMBERS**, plus **TRANSFER_OWNERSHIP** si la cible est responsable. Retirer le **dernier** responsable renvoie `409`.

```json
{ "message": "mamy.rabe@techmada.mg a été retiré de Tech Madagascar.", "status": 200, "data": null }
```

Retirer une personne supprime son appartenance, donc son rôle — mais **pas son compte**, ni les événements de l'organisation (ils appartiennent à l'organisation, pas à la personne).

### `PUT /api/organizers/{organizerId}/owner`

Droit **TRANSFER_OWNERSHIP** (owner, ou super administrateur).

```json
{ "userId": 4, "demoteTo": "admin" }
```

- cible par `userId` ou `email`, rattachée à l'organisation si elle ne l'était pas encore ;
- `demoteTo` décide du sort des anciens responsables : `admin` par défaut (ils gardent la main sur l'organisation qu'ils ont dirigée), une autre valeur de rôle, ou **`null` explicite** pour les conserver **co-responsables** ;
- la promotion est appliquée **avant** les rétrogradations : l'organisation n'est à aucun moment sans responsable.

Réponse `200` : `data.owner` (le nouveau responsable) et `data.members` (la liste à jour).

### Erreurs métier communes

Ces endpoints lèvent des exceptions qui étendent `BusinessException` ; `BusinessExceptionSubscriber` les rend dans l'enveloppe maison (`{message, status, data: null}`) avec leur propre code HTTP. C'est le seul endroit de l'API où les erreurs sont traitées proprement.

| Cas | Code | Message |
|---|---|---|
| Organisation inconnue | `404` | `Organisation introuvable (id: 99).` |
| Utilisateur inconnu | `404` | `Utilisateur introuvable (…).` |
| Ni `userId` ni `email` fourni | `404` | `Utilisateur introuvable (renseignez « userId » ou « email »).` |
| La personne n'est pas membre | `404` | `… ne fait pas partie de ….` |
| Rôle invalide ou absent | `400` | `Rôle « … » inconnu. Valeurs acceptées : owner, admin, member.` |
| Dernier responsable | `409` | `Impossible : … doit conserver au moins un responsable. …` |
| Droit manquant | `403` | réponse de `denyAccessUnlessGranted` (hors enveloppe) |

⚠️ Le `403` est la seule exception : il vient de `denyAccessUnlessGranted()`, pas de `MembershipException`, donc il n'est **pas** enveloppé (`MembershipException::accesRefuse()` existe mais n'est utilisée nulle part). Noter aussi qu'une entrée malformée (`userId` inexistant, `email` vide) remonte en `404` et non en `400` — toutes les résolutions d'entité partagent le même message.

### Amorçage par la console

Une organisation sans membre est **inaccessible via l'API** : le droit découle de l'appartenance, donc personne ne peut s'y rattacher. C'est le cas de celles créées par le payload imbriqué de `POST /api/events`. Il faut alors passer par la console :

```bash
php bin/console app:organizer:member list|assign|revoke|transfer|organizations \
  -o <id|nom> -u <id|email> -r owner|admin|member [--keep-owners]
php bin/console app:user:super-admin <email> [--revoke]
```

`-o` accepte l'identifiant **ou** le nom exact de l'organisation, `-u` l'identifiant ou l'email ; `--keep-owners` est l'équivalent console du `demoteTo: null` de l'endpoint de transfert.

---

## Routes techniques

| Chemin | Rôle |
|---|---|
| `/api/docs` | Documentation OpenAPI (Swagger UI en HTML, document brut avec `Accept: application/vnd.openapi+json`) |
| `/api` | Point d'entrée Hydra listant les ressources |
| `/api/contexts/{shortName}` | Contextes JSON-LD |
| `/api/errors/{status}`, `/api/validation_errors/{id}` | Représentation des erreurs API Platform |
| `/_error/{code}` | Prévisualisation des pages d'erreur (environnement `dev` uniquement) |

⚠️ `Accept: application/json` est **refusé** par `/api/docs` (`406`) : les types servis sont `text/html`, `application/ld+json`, `application/vnd.openapi+json` et `application/vnd.openapi+yaml`.

La documentation OpenAPI peut aussi être exportée :

```bash
php bin/console api:openapi:export
```

### D'où vient `/api/docs`

Le document est assemblé à partir de **deux sources**, et c'est utile à savoir avant d'y chercher une route manquante :

1. **Les opérations déclarées dans les `#[ApiResource]`** des entités — tout ce qui concerne les événements, catégories, lieux et organisateurs. API Platform les décrit automatiquement ; un `OpenApiOperation` sur l'opération précise le résumé et les paramètres.
2. **`src/OpenApi/UndocumentedEndpointsFactory.php`**, un décorateur de `api_platform.openapi.factory`, pour les routes écrites en contrôleurs Symfony classiques : `/api/health`, l'inscription, le renouvellement de jeton, le profil, la création d'organisation et les cinq endpoints de membres. API Platform ne décrit que ce qu'il route lui-même, ces routes lui étant invisibles.

Le décorateur **n'ajoute que de la description** : il reçoit le document généré et y insère des chemins. Aucune route, aucun contrôleur, aucun contrôle d'accès n'en dépend — une erreur à cet endroit se voit dans `/api/docs`, jamais dans le comportement de l'API. La contrepartie est qu'il s'agit de documentation écrite à la main : **elle doit être mise à jour en même temps que les contrôleurs concernés**.

`POST /api/auth/login` échappe aux deux : c'est le bundle Lexik qui le documente, en décorant la même factory.

Ajouter une route à la documentation, selon son origine :

- opération d'un `#[ApiResource]` → rien à faire, sinon affiner son `OpenApiOperation` ;
- contrôleur Symfony classique → ajouter un chemin dans le décorateur, sur le modèle des méthodes existantes.

---

## CORS

`config/packages/nelmio_cors.yaml` autorise, pour `^/api/`, **toutes les origines** (`allow_origin: ['*']`) avec `allow_credentials: true`, les méthodes `GET, POST, PUT, DELETE, OPTIONS` et les en-têtes `Content-Type` / `Authorization`. La variable `CORS_ALLOW_ORIGIN` présente dans `.env` n'est pas utilisée par cette configuration.

⚠️ **`PATCH` est absent** d'`allow_methods` : la seule route qui l'accepte (`/api/organizers/{organizerId}/members/{userId}`) est donc inutilisable depuis un navigateur. Elle accepte aussi `PUT`, ce qui contourne le problème côté client ; l'ajouter à la configuration serait plus propre.

⚠️ `allow_origin: ['*']` **avec** `allow_credentials: true` est une combinaison que les navigateurs refusent et, surtout, une ouverture totale : à restreindre aux origines des applications admin et mobile avant toute mise en production (`CORS_ALLOW_ORIGIN` est là pour ça, il suffit de la brancher).

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

# Sonde de disponibilité (sans token)
curl http://localhost:8000/api/health

# Mes organisations et mes événements
curl http://localhost:8000/api/organizers/me -H "Authorization: Bearer $TOKEN"
curl http://localhost:8000/api/user/me/organizations -H "Authorization: Bearer $TOKEN"
curl http://localhost:8000/api/events/me -H "Authorization: Bearer $TOKEN"

# Créer une organisation (le créateur en devient responsable)
curl -X POST http://localhost:8000/api/organizers \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Antsika Prod","email":"contact@antsika.mg"}'

# Membres : lister, rattacher, changer de rôle, retirer, transférer
curl http://localhost:8000/api/organizers/1/members -H "Authorization: Bearer $TOKEN"

curl -X POST http://localhost:8000/api/organizers/1/members \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"email":"mamy.rabe@techmada.mg","role":"admin"}'

curl -X PUT http://localhost:8000/api/organizers/1/members/4 \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"role":"member"}'

curl -X DELETE http://localhost:8000/api/organizers/1/members/4 \
  -H "Authorization: Bearer $TOKEN"

curl -X PUT http://localhost:8000/api/organizers/1/owner \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"userId":4,"demoteTo":"admin"}'
```

## Documents liés

- `STRUCTURE_BDD.md` — schéma de la base de données
- `API_RECHERCHE_EVENTS.md` — détail des endpoints de recherche
- `CLAUDE.md` — architecture et conventions du projet
