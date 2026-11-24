# Règles métier déduites du code

Chaque règle indique où elle est appliquée : **BDD** (contrainte SQL), **ORM** (mapping / callbacks), **Validation** (contraintes Symfony), **Service / Contrôleur** (code applicatif). Une règle appliquée seulement côté applicatif peut être contournée par un accès SQL direct ou par un autre chemin de code.

## 1. Événements et catalogue

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-EVT-01 | Un événement appartient obligatoirement à **une** organisation et **un** lieu ; la catégorie est facultative. | BDD (NOT NULL / nullable) | `src/Entity/Event.php`, migration |
| BR-EVT-02 | Un nouvel événement est créé en **brouillon** (`status = false`). | ORM (constructeur) | `Event::__construct()` |
| BR-EVT-03 | Les recherches publiques ne renvoient que les événements publiés (`status = true`), triés par `started_at` décroissant. | Repository | `EventRepository::findByCategory()`, `searchEvents()` |
| BR-EVT-04 | La liste générale `GET /api/events` **ne filtre pas** sur `status` : les brouillons y apparaissent. Tri par `created_at` décroissant, pagination 1–20 (10 par défaut). | Contrôleur | `src/Controller/Api/Event/GetEventsController.php` |
| BR-EVT-05 | « Mes événements » = événements des organisations dont l'utilisateur est membre (quel que soit son rôle), brouillons compris. Chemin : `event → organizer → organizer_membership → user`. | Repository | `EventRepository::findFiltered()` (avec `$scope`), `countFiltered()` |
| BR-EVT-06 | La recherche par titre est un `LIKE '%…%'` **sensible à la casse** (PostgreSQL). | Repository | `EventRepository::searchEvents()` |
| BR-EVT-07 | La fin d'un événement **devrait** être postérieure à son début : respecté par les fixtures, mais **aucune contrainte** (ni `CHECK`, ni validation) ne l'impose. | Fixtures uniquement | `EventFixtures::buildDates()` |
| BR-EVT-08 | À la création via `POST /api/events`, l'organisation doit être liée par `id` (`400` si absent, erreur si introuvable : elle se crée via `POST /api/organizers`) ; catégorie et lieu sont soit liés par `id`, soit **créés** à partir des champs fournis. Les tarifs `ticket_type[]` sont créés et rattachés. | Dénormaliseur | `src/Serializer/Denormalizer/EventInputDenormalizer.php` |
| BR-EVT-09 | `image_url` n'est jamais renseigné par l'API (reste `[]`) et n'est pas exposé. | — | `EventInputDenormalizer`, absence de groupe sur `Event::$imageUrl` |
| <a id="br-evt-10"></a>BR-EVT-10 | **Filtres communs** de `GET /api/events`, `GET /api/events/me` et `GET /api/organizers/{id}/events`, tous facultatifs et combinables (ET), absent = « tous » : `title` (partiel, insensible à la casse, `%` et `_` littéraux, vide = pas de filtre), `organizer`, `category` (identifiants), `status` (`published`/`draft`), `period` (`upcoming` : début futur ; `ongoing` : début passé et fin à venir ; `past` : fin révolue), `startDate`/`endDate` (`AAAA-MM-JJ` sur `started_at`, bornes incluses, `endDate` couvre toute la journée), `sort` (`createdAt:desc` par défaut, `startedAt:asc`, `startedAt:desc`, `title:asc` ; `id DESC` départage les ex æquo). `itemsTotal` compte avec les filtres. Valeur inconnue, vide, répétée ou mal formée → `400` explicite. | Service + Repository | `EventFiltersParser`, `EventRepository::findFiltered()` / `countFiltered()` |
| BR-EVT-11 | **Visibilité de `GET /api/events`** : anonyme et utilisateurs ordinaires ne voient que les événements **publiés** ; le `ROLE_SUPER_ADMIN` garde la vue de gestion (brouillons compris). `status=draft` sans être super administrateur → `403`. Changement du 2026-11-24 : la liste servait auparavant tous les événements, brouillons compris, à tout le monde. | Contrôleur | `GetEventsController` |
| BR-EVT-12 | **`GET /api/events/me`** : périmètre = événements des organisations dont l'utilisateur est membre, brouillons compris. `organizer=<id>` hors de ses organisations → `403` (une liste vide laisserait croire que l'organisation n'a pas d'événements). | Contrôleur + Repository | `GetMyEventsController` |

## 2. Billetterie

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-TKT-01 | Un tarif appartient à exactement un événement ; il est supprimé avec lui ou lorsqu'il est retiré de sa collection. | ORM (`cascade remove`, `orphanRemoval`) | `Event::$ticket_type` |
| BR-TKT-02 | Les prix sont des **entiers en ariary** ; `0` signifie gratuit. | Convention (fixtures) | `EventFixtures::TARIFS`, `TicketType::$prix` (`int`) |
| BR-TKT-03 | La somme des quotas (`quantite_max`) **peut dépasser** la jauge du lieu (`location.size`) : la sur-réservation est un cas testé volontairement, l'alerte étant de la responsabilité de l'interface d'administration. La vente est bornée par le quota **du tarif**, pas par la jauge (D-07). | Aucun contrôle en base | `EventFixtures::createTicketTypes()`, `LocationFixtures` (docblock) |
| BR-TKT-04 | Un événement peut n'avoir **aucun** tarif. | BDD (relation 0..N) | `EventFixtures` (1 sur 12 sans billetterie) |
| BR-TKT-05 | On ne vend jamais plus que `quantite_max` : réservation par un `UPDATE` conditionnel atomique, et CHECK `0 ≤ quantite_reservee ≤ quantite_max`. Le quota ne peut pas être baissé sous les places déjà prises. Vérifié sous concurrence (20 achats simultanés sur 5 places : 5 ventes, 15 refus). | BDD + Service | `OrderService::reserve()`, migration `Version20261120125310` |
| BR-TKT-06 | Un tarif vendu ne peut plus être supprimé (`RESTRICT`). | BDD | `order_item.ticket_type_id` |

## 3. Organisations et appartenances

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-ORG-01 | Un utilisateur a **au plus un rôle** par organisation. | BDD (unique) + Service | `uniq_membership_user_organizer`, `OrganizerMembershipService::assign()` (idempotent : met à jour le rôle si l'appartenance existe) |
| BR-ORG-02 | Rôles hiérarchiques `owner (30) > admin (20) > member (10)` ; les contrôles s'écrivent « au moins tel niveau ». | Enum | `OrganizerRole::level()`, `isAtLeast()` |
| BR-ORG-03 | **Une organisation garde toujours au moins un responsable** : refus de rétrograder ou de retirer le dernier `owner` (`MembershipException::dernierResponsable`). | Service uniquement | `OrganizerMembershipService::changeRole()`, `revoke()`, `assertNotLastOwner()` |
| <a id="br-org-04"></a>BR-ORG-04 | Le créateur d'une organisation via `POST /api/organizers` en devient responsable **dans la même transaction**. Toute organisation passe désormais par cette route : le payload imbriqué de `POST /api/events` ne crée plus d’organisation. | Contrôleur (transaction) | `CreateOrganizerController` (`wrapInTransaction`) |
| BR-ORG-05 | Transfert de responsabilité : le nouveau responsable est promu **avant** la rétrogradation des précédents (par défaut en `admin`, ou laissés co-responsables), le tout en un seul `flush`. | Service | `OrganizerMembershipService::transferOwnership()` |
| BR-ORG-06 | Plusieurs responsables simultanés sont autorisés (co-responsables). | Modèle | `Organizer::getOwners()` |
| BR-ORG-07 | Toutes les écritures d'appartenance passent par `OrganizerMembershipService` (API et console `app:organizer:member`). | Convention | `src/Command/OrganizerMemberCommand.php`, contrôleurs `Api/Membership/*` |
| BR-ORG-08 | À la création via l'API : `name`, `email` et `paymentMethods` obligatoires, longueurs max 100/100/50/100, email validé, site validé comme URL, email **non unique**. | Contrôleur | `CreateOrganizerController` |
| BR-ORG-09 | La liste des membres est triée par niveau de rôle décroissant puis par nom (tri fait **en PHP**). | Repository | `OrganizerMembershipRepository::findByOrganizer()` |
| <a id="br-org-10"></a>BR-ORG-10 | **Une organisation créée par `POST /api/organizers` a au moins un compte mobile money** (`paymentMethods`, liste non vide). Un seul compte par opérateur (MVola, Orange Money, Airtel Money) : doublon refusé par le parser **et** par la base. Les comptes sont persistés avec l'organisation, dans la même transaction. | Service + BDD (unicité) | `PaymentMethodInputParser::parse()`, `uniq_payment_method_organizer_provider` |
| BR-ORG-11 | Chaque compte porte un **numéro** et le **nom du titulaire**, tous deux obligatoires. Le numéro est normalisé en `+261XXXXXXXXX` et doit avoir un préfixe cohérent avec l'opérateur (MVola 034/038, Orange 032/037, Airtel 033). | Enum + Service + BDD (`CHECK`) | `MobileMoneyProvider::normalizePhone()`, `chk_payment_method_phone` |
| BR-ORG-12 | Les comptes mobile money ne sont visibles que des **membres** de l'organisation (réponses `/api/user/me/organizations` et création). Les ressources publiques `Organizer` ne les exposent pas. | Sérialisation | `Organizer::$paymentMethods` (sans `#[Groups]`), `MembershipPresenter::organization()` |
| BR-ORG-13 | `PATCH /api/organizers/{id}` (droit `ORGANIZER_EDIT`, admin+) est **partiel** : seuls les champs présents changent, au moins un est requis ; `name` / `email` ne peuvent pas être vidés, `phone` / `website` se vident avec `null`. Tout est validé avant la première écriture. | Service | `OrganizerUpdater`, `OrganizerInputParser` |
| BR-ORG-14 | `paymentMethods` dans un `PATCH` **remplace la liste complète** avec les règles de BR-ORG-10 / 11 (liste non vide : on ne peut pas retirer le dernier compte). Le remplacement se fait par opérateur : compte conservé = mis à jour sur place (`id` et `created_at` intacts), opérateur absent = supprimé, nouvel opérateur = ajouté. `organizer.updated_at` est rafraîchi à la main (Q-29). | Service | `OrganizerUpdater::syncPaymentMethods()` |
| BR-ORG-15 | `GET /api/organizers/{id}` (droit `ORGANIZER_VIEW`) renvoie la fiche complète **avec `paymentMethods` et le rôle de l'appelant** ; un `ROLE_SUPER_ADMIN` non membre la voit sans rôle. La ressource publique `GET /api/organizer/{id}` reste sans comptes. | Contrôleur + présentateur | `GetOrganizerController`, `MembershipPresenter::detail()` |
| BR-ORG-16 | `GET /api/organizers/{id}/events` (`ORGANIZER_VIEW`) liste les événements d'**une** organisation, brouillons compris, avec les **mêmes filtres que `/api/events`** (BR-EVT-10) ; l'organisation est fixée par l'URL (`?organizer=` ignoré). | Repository | `EventRepository::findFiltered()` |
| BR-ORG-17 | **Statistiques** (`GET /api/organizers/{id}/stats`, `ORGANIZER_VIEW`) : une vente = commande `paid` uniquement ; revenus = `ticket_order.total_amount` (ariary), billets = `order_item.quantity`. Totaux et top couvrent toute la vie de l'organisation, la série (jour ou mois, datée par `paid_at`, complétée par des zéros) est bornée par `from`/`to` (max 366 jours / 36 mois). | Service (SQL agrégé) | `OrganizerStatsService` |
| BR-ORG-18 | **Recherche d'utilisateurs à inviter** (`GET /api/organizers/{id}/member-candidates`, `ORGANIZER_MANAGE_MEMBERS`, admin+) : terme ≥ 3 caractères, 10 résultats max, champs minimaux, membres actuels exclus. Endpoint borné car il expose un annuaire. | Contrôleur + repository | `SearchMemberCandidatesController`, `UserRepository::searchInvitable()` |

## 4. Droits d'accès dérivés des données

| ID | Règle | Source |
|---|---|---|
| BR-SEC-01 | `ROLE_SUPER_ADMIN` (dans `user.roles`) court-circuite tous les contrôles d'appartenance. | `User::isSuperAdmin()`, `User::hasRoleIn()`, `OrganizerVoter` |
| BR-SEC-02 | VIEW = `member`+, EDIT et MANAGE_MEMBERS = `admin`+, TRANSFER_OWNERSHIP = `owner`. Les droits sur une organisation ne donnent **aucun** droit sur une autre. | `src/Security/Voter/OrganizerVoter.php` |

## 5. Utilisateurs

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-USR-01 | L'email est unique et normalisé (minuscules, `trim`). | BDD + ORM (setter) + Validation | `UNIQ_IDENTIFIER_EMAIL`, `User::setEmail()`, `UniqueEntity` |
| BR-USR-02 | Langue par défaut `fr` si non fournie. | ORM (`PrePersist`) | `User::onPrePersist()` |
| BR-USR-03 | Tout utilisateur a `ROLE_USER` (ajouté dynamiquement). L'inscription l'enregistre aussi explicitement dans `roles`. | ORM / Service | `User::getRoles()`, `UserRegistrationService::createUserFromDTO()` |
| BR-USR-04 | Inscription : email valide, mot de passe 4 à 72 caractères, prénom/nom ≤ 50, téléphone ≤ 30, langue ≤ 10. Seul le **DTO** est validé (les contraintes de l'entité `User`, dont `UniqueEntity`, ne sont pas évaluées sur ce chemin). | Validation (DTO) | `src/DTO/RegisterDTO.php`, `UserRegistrationService::validateDTO()` |
| BR-USR-05 | L'inscription émet directement un access token et un refresh token (30 jours). | Service | `UserRegistrationService::execute()`, `JwtSecurityService` |
| BR-USR-06 | `is_active` est décrit comme « date d'activation » mais n'est ni renseigné à l'inscription ni vérifié à la connexion : **aucune notion de compte inactif n'est appliquée**. | — | `src/Entity/User.php`, absence de `UserChecker` |

## 6. Horodatage automatique

| Table | `created_at` | `updated_at` |
|---|---|---|
| `user`, `organizer_membership` | `PrePersist` (si vide) | `PrePersist` puis **`PreUpdate`** à chaque modification |
| `event`, `category`, `location`, `organizer`, `ticket_type` | constructeur PHP | constructeur PHP **uniquement** — jamais rafraîchi lors d'une mise à jour |

Dans les constructeurs, la condition `if ($this->getId() === null)` est toujours vraie (Doctrine n'appelle pas le constructeur lors de l'hydratation) ; elle est sans effet.

## 7. Workflows de statut

Deux statuts existent : `event.status` (ci-dessous) et `ticket_order.status` (section 8).

`event.status` (booléen brouillon / publié). Aucun workflow (Symfony Workflow, machine à états) n'est configuré, et aucun endpoint ne fait passer un événement de brouillon à publié à ce jour (**À confirmer** : publication prévue côté administration ?).

## 8. Commande de billets

Toutes les écritures passent par `src/Service/Order/OrderService.php` (comme `OrganizerMembershipService` pour les appartenances). Conception détaillée : [`design-orders.md`](design-orders.md).

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-ORD-01 | Seul un utilisateur connecté commande ; une commande porte sur **un seul** événement. | Contrôleur + BDD | `PlaceOrderController`, `ticket_order.event_id` NOT NULL |
| BR-ORD-02 | L'événement doit être publié (un brouillon répond `404`) et non terminé (`409`) ; la vente reste ouverte pendant l'événement, jusqu'à `end_at`. | Service | `OrderService::eventOnSale()` |
| BR-ORD-03 | 1 à 10 billets par commande, tous tarifs confondus ; quantités > 0 ; un tarif par ligne ; tarifs de l'événement commandé uniquement (`400`). | Service + BDD | `OrderService::normalizeLines()`, CHECK `chk_item_quantity`, `uniq_item_order_type` |
| BR-ORD-04 | Le total est calculé à partir des prix en base ; nom et prix du tarif sont **figés** sur la ligne. | Entité + BDD | `OrderItem::fromTicketType()`, CHECK `chk_item_total` |
| BR-ORD-05 | Si une ligne manque de places, **rien** n'est réservé ni enregistré (`409`, le message nomme le tarif). | Service (transaction) | `OrderService::place()` |
| BR-ORD-06 | Commande payante : `pending`, places réservées 15 min (`expires_at`). Commande gratuite : `paid` immédiatement, billets émis. | Service + BDD | `OrderService::place()`, CHECK `chk_order_pending` |
| BR-ORD-07 | Même `Idempotency-Key` pour le même acheteur ⇒ même commande, réservée une seule fois (`200` au lieu de `201`). | Service + BDD | `uniq_order_idempotency` |
| BR-ORD-08 | Seul l'acheteur annule, et seulement une commande `pending` ; les places sont rendues. Annuler la commande d'un autre répond `404`. | Service | `OrderService::cancel()` |
| BR-ORD-09 | Le paiement émet **un billet par place**, avec un code unique ; une confirmation rejouée n'émet rien de plus. | Entité + Service | `OrderItem::issueTickets()`, `OrderService::markAsPaid()` |
| BR-ORD-10 | Les commandes `pending` échues passent à `expired` et rendent leurs places, une seule fois (`FOR UPDATE SKIP LOCKED`). | Service + commande planifiée | `OrderService::expirePending()`, `app:order:expire` |
| BR-ORD-11 | Une commande est visible par l'acheteur, les membres de l'organisation de l'événement et le super administrateur ; pour les autres `404`. Les coordonnées de l'acheteur ne sont montrées qu'à l'organisation. | Voter | `OrderVoter`, `GetOrderController` |
| BR-ORD-12 | Une commande n'est jamais supprimée ; elle survit au compte de l'acheteur (`SET NULL`) et bloque la suppression de l'événement (`RESTRICT`). | BDD | migration `Version20261120125310` |
| BR-ORD-13 | Invariant : `ticket_type.quantite_reservee` = somme des quantités des commandes `pending` + `paid`. Vérifiable par la requête de [`operations.md`](operations.md#5-requêtes-sql-utiles-lecture). | Service | `OrderService` |

Statuts (`OrderStatus`) : `pending` → `paid` | `cancelled` | `expired` ; `paid` → `refunded` | `cancelled` (lot 2, non implémenté).

