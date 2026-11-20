# Dictionnaire de données

Sources communes : `src/Entity/*.php` (mapping), `migrations/Version20260814185842.php` (DDL), confirmés par `doctrine:schema:validate` (schéma synchronisé au 2026-11-20).

Remarques valables pour **toutes** les tables :

- **Clé primaire** : `id INT`, généré par une séquence PostgreSQL (`SERIAL`, défaut `nextval('<table>_id_seq')`), déclaré `#[ORM\Id] #[ORM\GeneratedValue]` (stratégie `AUTO`). La configuration `identity_generation_preferences: identity` (`config/packages/doctrine.yaml`) n'a pas produit de colonnes `IDENTITY` : la base réelle a `is_identity = NO` sur toutes les colonnes `id`.
- **Dates** : `TIMESTAMP(0) WITHOUT TIME ZONE` (précision à la seconde, sans fuseau). Les `DateTimeImmutable` portent le commentaire SQL `(DC2Type:datetime_immutable)`.
- **Aucune valeur par défaut SQL** n'est définie, hormis `DEFAULT NULL` sur les colonnes nullables : toutes les valeurs initiales sont posées côté PHP.
- **Pas de soft delete, pas de versioning (`#[ORM\Version]`), pas d'héritage STI/CTI** dans le projet.

---

## `event`

- **Entité** : `App\Entity\Event` — `src/Entity/Event.php`
- **Repository** : `src/Repository/EventRepository.php`
- **Rôle métier** : un événement publié (ou en brouillon) par une organisation, dans un lieu, avec ses tarifs.

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `category_id` | `INT` | **oui** | `NULL` | — | FK → `category.id` | Catégorie (facultative) |
| `organizer_id` | `INT` | non | — | — | FK → `organizer.id` | Organisation propriétaire de l'événement |
| `location_id` | `INT` | non | — | — | FK → `location.id` | Lieu d'accueil |
| `title` | `VARCHAR` | non | — | 150 | — | Titre |
| `description` | `TEXT` | non | — | — | — | Description longue |
| `started_at` | `TIMESTAMP(0)` | non | — | — | — | Date/heure de début |
| `end_at` | `TIMESTAMP(0)` | non | — | — | — | Date/heure de fin |
| `image_url` | `TEXT` | non | — | — | commentaire `(DC2Type:array)` | Liste d'URL d'images, stockée en **tableau PHP sérialisé** (`Types::ARRAY`). Jamais alimentée ni exposée par l'API (aucun groupe de sérialisation, absente du dénormaliseur). |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Date de création (posée dans le constructeur) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Date de modification (posée dans le constructeur uniquement, non rafraîchie) |
| `status` | `BOOLEAN` | non | — | — | — | Publication : `true` = publié, `false` = brouillon. Initialisé à `false` dans le constructeur. |

- **Clés étrangères** :
  - `FK_3BAE0AA712469DE2` (`category_id` → `category.id`) — pas d'`ON DELETE` (= `NO ACTION`).
  - `FK_3BAE0AA7876C4DDA` (`organizer_id` → `organizer.id`) — pas d'`ON DELETE`.
  - `FK_3BAE0AA764D218E` (`location_id` → `location.id`) — pas d'`ON DELETE`.
  - Côté ORM : `cascade: ['persist']` sur les trois `ManyToOne` (création à la volée par `EventInputDenormalizer`).
- **Index** : `IDX_3BAE0AA712469DE2 (category_id)`, `IDX_3BAE0AA7876C4DDA (organizer_id)`, `IDX_3BAE0AA764D218E (location_id)` — générés automatiquement par Doctrine sur chaque FK (jointures et contrôle d'intégrité). **Aucun index** sur `status`, `started_at`, `created_at` ou `title`, pourtant utilisés en filtre/tri (voir [`open-questions.md`](open-questions.md#q-10)).
- **Statuts** : `status` booléen (`true` publié / `false` brouillon) ; les repositories publics filtrent `status = true` (`findByCategory()`, `searchEvents()`), la liste générale et « mes événements » ne filtrent pas.
- **Particularités** : timestamps posés dans le constructeur (pas de lifecycle callback) ; collection `ticket_type` en `cascade: ['persist', 'remove']` + `orphanRemoval: true`.

---

## `category`

- **Entité** : `App\Entity\Category` — `src/Entity/Category.php`
- **Rôle métier** : rubrique de classement des événements (concert, festival…), avec une couleur d'affichage.

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `name` | `VARCHAR` | non | — | 100 | — (non unique) | Libellé de la catégorie |
| `color` | `VARCHAR` | non | — | 50 | — | Couleur d'affichage (code hexadécimal `#rrggbb` dans les fixtures, format non contraint) |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Création (constructeur) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Modification (constructeur uniquement) |

- **Clés étrangères** : aucune sortante. Référencée par `event.category_id`.
- **Index / unicité** : aucun hors PK. Le nom n'est **pas unique** : deux catégories homonymes sont possibles (notamment via la création imbriquée de `POST /api/events`).
- **Particularités** : relation inverse `events` (`OneToMany`, sans cascade, sans orphanRemoval).

---

## `location`

- **Entité** : `App\Entity\Location` — `src/Entity/Location.php`
- **Rôle métier** : lieu d'accueil d'événements, avec sa capacité.

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `name` | `VARCHAR` | non | — | 100 | — (non unique) | Nom du lieu |
| `size` | `INT` | non | — | — | — | Jauge (nombre de places). Sert de référence aux quotas de billets (`src/DataFixtures/LocationFixtures.php`) ; aucune contrainte de positivité. |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Création (constructeur) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Modification (constructeur uniquement) |

- **Clés étrangères** : aucune sortante. Référencée par `event.location_id`.
- **Index / unicité** : aucun hors PK.
- **Particularités** : collection inverse `events` en **`orphanRemoval: true`** — retirer un événement de la collection d'un lieu, ou supprimer le lieu via l'ORM, **supprime les événements** (voir [`relations.md`](relations.md)). Aucune adresse ni coordonnée géographique n'est stockée.

---

## `ticket_type`

- **Entité** : `App\Entity\TicketType` — `src/Entity/TicketType.php`
- **Rôle métier** : une offre tarifaire d'un événement (nom du tarif, prix, quota de places).

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `event_id` | `INT` | non | — | — | FK → `event.id` | Événement concerné |
| `name` | `VARCHAR` | non | — | 50 | — | Nom du tarif (« VIP », « Étudiant »…) |
| `prix` | `INT` | non | — | — | — | Prix **entier en ariary** (`src/DataFixtures/EventFixtures.php`) ; `0` = gratuit. Monnaie non stockée. |
| `quantite_max` | `INT` | non | — | — | — | Nombre maximal de billets de ce tarif (quota) |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Création (constructeur) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Modification (constructeur uniquement) |

- **Clés étrangères** : `FK_BE05421171F7E88B` (`event_id` → `event.id`) — pas d'`ON DELETE` en base ; la suppression en cascade est faite **par l'ORM** (`Event.ticket_type` : `cascade: ['remove']`, `orphanRemoval: true`).
- **Index** : `IDX_BE05421171F7E88B (event_id)` (FK).
- **Particularités** : nommage des colonnes en français et en snake_case côté PHP (`$prix`, `$quantite_max`), contrairement au reste du modèle. Aucune contrainte `prix >= 0`, `quantite_max > 0`, ni de somme des quotas ≤ `location.size` (le dépassement est même un cas voulu des fixtures).

---

## `organizer`

- **Entité** : `App\Entity\Organizer` — `src/Entity/Organizer.php`
- **Rôle métier** : organisation (structure, société, association) qui produit des événements et regroupe des membres.

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `name` | `VARCHAR` | non | — | 100 | — (non unique) | Raison sociale / nom public |
| `email` | `VARCHAR` | non | — | 100 | — (**non unique**, choix assumé dans `CreateOrganizerController`) | Email de contact de l'organisation |
| `phone` | `VARCHAR` | oui | `NULL` | 50 | — | Téléphone de contact |
| `website` | `VARCHAR` | oui | `NULL` | 100 | — | Site web (validé `FILTER_VALIDATE_URL` à la création via `POST /api/organizers`) |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Création (constructeur) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Modification (constructeur uniquement) |

- **Clés étrangères** : aucune sortante. Référencée par `event.organizer_id` et `organizer_membership.organizer_id`.
- **Index / unicité** : aucun hors PK.
- **Particularités** :
  - pas de colonne `user_id` : le lien avec les utilisateurs passe exclusivement par `organizer_membership` ;
  - collection `events` en **`orphanRemoval: true`** (suppression de l'organisation via l'ORM ⇒ suppression de ses événements et de leurs tarifs) ;
  - collection `memberships` en `cascade: ['persist']` + `orphanRemoval: true`.

---

## `organizer_membership`

- **Entité** : `App\Entity\OrganizerMembership` — `src/Entity/OrganizerMembership.php`
- **Repository** : `src/Repository/OrganizerMembershipRepository.php`
- **Rôle métier** : appartenance d'un utilisateur à une organisation, avec son rôle dans cette organisation.

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant technique |
| `user_id` | `INT` | non | — | — | FK → `"user".id` **ON DELETE CASCADE** ; UK (avec `organizer_id`) | Membre |
| `organizer_id` | `INT` | non | — | — | FK → `organizer.id` **ON DELETE CASCADE** ; UK (avec `user_id`) | Organisation |
| `role` | `VARCHAR` | non | — (PHP : `member`) | 20 | enum PHP `OrganizerRole` (pas de `CHECK` SQL) | Rôle dans l'organisation |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Date d'entrée dans l'organisation (`PrePersist`) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Dernière modification (`PrePersist` / `PreUpdate`) |

- **Clés étrangères** :
  - `FK_E2DB4746A76ED395` (`user_id` → `"user".id`) `ON DELETE CASCADE`.
  - `FK_E2DB4746876C4DDA` (`organizer_id` → `organizer.id`) `ON DELETE CASCADE`.
  - Côté ORM : les deux collections inverses (`User.memberships`, `Organizer.memberships`) sont en `cascade: ['persist']` + `orphanRemoval: true`.
- **Index et unicité** :

  | Nom | Colonnes | Type | Justification |
  |---|---|---|---|
  | `uniq_membership_user_organizer` | `(user_id, organizer_id)` | UNIQUE | Un seul rôle par personne et par organisation (commentaire de l'entité). Garantit aussi l'absence de doublons dans `EventRepository::findByUser()` (pas besoin de `DISTINCT`). |
  | `idx_membership_organizer_role` | `(organizer_id, role)` | INDEX | `countByOrganizerAndRole()` / `findByOrganizerAndRole()` : compter les responsables (invariant « au moins un owner »). |
  | `IDX_E2DB4746A76ED395` | `(user_id)` | INDEX | Index FK auto-généré ; redondant avec le préfixe de l'index unique. |
  | `IDX_E2DB4746876C4DDA` | `(organizer_id)` | INDEX | Index FK auto-généré ; redondant avec le préfixe de `idx_membership_organizer_role`. |

- **Valeurs de l'enum `role`** (`src/Enum/OrganizerRole.php`) :

  | Valeur | Libellé | Niveau | Droits (via `OrganizerVoter`) |
  |---|---|---|---|
  | `owner` | Responsable | 30 | Tout, dont transfert de responsabilité |
  | `admin` | Administrateur | 20 | Modifier l'organisation, gérer les membres |
  | `member` | Membre | 10 | Consulter l'organisation et ses membres |

- **Particularités** : `#[ORM\HasLifecycleCallbacks]` — seule entité, avec `User`, à rafraîchir `updated_at` automatiquement.

---

## `"user"`

- **Entité** : `App\Entity\User` — `src/Entity/User.php`
- **Repository** : `src/Repository/UserRepository.php` (implémente `PasswordUpgraderInterface`)
- **Rôle métier** : compte utilisateur de la plateforme (authentification et appartenance aux organisations).
- Le nom de table est un mot réservé PostgreSQL : il est déclaré ``#[ORM\Table(name: '`user`')]`` et apparaît entre guillemets (`"user"`) dans le SQL.

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `email` | `VARCHAR` | non | — | 180 | UNIQUE `UNIQ_IDENTIFIER_EMAIL` ; `NotBlank`, `Email`, `Length(max:180)` | Identifiant de connexion, normalisé en minuscules et sans espaces par `setEmail()` |
| `roles` | `JSON` | non | — | — | — | Rôles **globaux** Symfony (`ROLE_SUPER_ADMIN`…). `ROLE_USER` est ajouté dynamiquement par `getRoles()`. |
| `password` | `VARCHAR` | non | — | 255 | `NotCompromisedPassword` (sur l'entité) | Mot de passe **haché** (hasher `auto`) |
| `firstname` | `VARCHAR` | non | — | 50 | `NotBlank`, `Length(max:50)` | Prénom |
| `lastname` | `VARCHAR` | non | — | 50 | `NotBlank`, `Length(max:50)` | Nom |
| `phone` | `VARCHAR` | oui | `NULL` | 30 | `Length(max:30)` | Téléphone |
| `language` | `VARCHAR` | oui | `NULL` (PHP : `fr` au `PrePersist`) | 10 | `Length(max:10)` | Langue préférée, code ISO 639-1 attendu (commentaire de l'entité) |
| `is_active` | `TIMESTAMP(0)` | oui | `NULL` | — | — | **Date d'activation** du compte malgré son nom (commentaire de l'entité). Type `DATETIME_MUTABLE` (pas de commentaire DC2Type). Non renseignée à l'inscription, jamais lue. |
| `created_at` | `TIMESTAMP(0)` | non | — | — | — | Création (`PrePersist`) |
| `updated_at` | `TIMESTAMP(0)` | non | — | — | — | Modification (`PrePersist` / `PreUpdate`) |

- **Clés étrangères** : aucune sortante. Référencée par `organizer_membership.user_id` (`ON DELETE CASCADE`).
- **Index / unicité** : `UNIQ_IDENTIFIER_EMAIL (email)` — identifiant de connexion (`security.yaml` : provider `entity`, `property: email`). Doublé par `#[UniqueEntity(fields: ['email'])]` côté validation.
- **Valeurs de `roles`** observées dans le code : `ROLE_USER` (implicite, et stocké explicitement par `UserRegistrationService`), `ROLE_SUPER_ADMIN` (`User::ROLE_SUPER_ADMIN`, géré par `app:user:super-admin`).
- **Particularités** : `#[ORM\HasLifecycleCallbacks]` (`onPrePersist`, `onPreUpdate`).

---

## `refresh_tokens`

- **Entité** : `App\Entity\RefreshToken` — `src/Entity/RefreshToken.php` (classe vide qui hérite de `Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken`, mapping hérité du bundle)
- **Configuration** : `config/packages/gesdinet_jwt_refresh_token.yaml`
- **Rôle métier** : jetons de rafraîchissement des sessions JWT (durée de vie 30 jours, usage unique).

| Colonne | Type SQL | Null | Défaut | Longueur | Contraintes | Description métier |
|---|---|---|---|---|---|---|
| `id` | `INT` (SERIAL) | non | séquence | — | PK | Identifiant |
| `refresh_token` | `VARCHAR` | non | — | 128 | UNIQUE `UNIQ_9BACE7E1C74F2195` | Valeur du jeton (aléatoire, stockée **en clair**) |
| `username` | `VARCHAR` | non | — | 255 | — | Identifiant de l'utilisateur = son **email** (`user_identity_field: email`) |
| `valid` | `TIMESTAMP(0)` | non | — | — | — | Date d'expiration (`+30 days`, `ttl: 2592000`). Type mutable (pas de commentaire DC2Type). |

- **Clés étrangères** : **aucune**. Le lien avec `"user"` est logique (`username` = `user.email`).
- **Index / unicité** : `UNIQ_9BACE7E1C74F2195 (refresh_token)` — recherche du jeton lors du rafraîchissement. Pas d'index sur `username` ni sur `valid`.
- **Particularités** : `single_use: true` — chaque rafraîchissement invalide l'ancien jeton et en émet un nouveau (rotation). Aucune purge planifiée des jetons expirés n'est configurée dans le projet.

---

## `doctrine_migration_versions` (technique)

- Créée et gérée par Doctrine Migrations (`TableMetadataStorageConfiguration`, d'après `doctrine:migrations:status`).
- Colonnes standard du bundle : `version`, `executed_at`, `execution_time` — **À confirmer** pour le détail exact (non décrit dans le code du projet).
- Ne contient que l'historique d'exécution ; ne doit pas être modifiée à la main.
