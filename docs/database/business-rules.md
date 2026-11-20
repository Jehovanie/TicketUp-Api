# Règles métier déduites du code

Chaque règle indique où elle est appliquée : **BDD** (contrainte SQL), **ORM** (mapping / callbacks), **Validation** (contraintes Symfony), **Service / Contrôleur** (code applicatif). Une règle appliquée seulement côté applicatif peut être contournée par un accès SQL direct ou par un autre chemin de code.

## 1. Événements et catalogue

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-EVT-01 | Un événement appartient obligatoirement à **une** organisation et **un** lieu ; la catégorie est facultative. | BDD (NOT NULL / nullable) | `src/Entity/Event.php`, migration |
| BR-EVT-02 | Un nouvel événement est créé en **brouillon** (`status = false`). | ORM (constructeur) | `Event::__construct()` |
| BR-EVT-03 | Les recherches publiques ne renvoient que les événements publiés (`status = true`), triés par `started_at` décroissant. | Repository | `EventRepository::findByCategory()`, `searchEvents()` |
| BR-EVT-04 | La liste générale `GET /api/events` **ne filtre pas** sur `status` : les brouillons y apparaissent. Tri par `created_at` décroissant, pagination 1–20 (10 par défaut). | Contrôleur | `src/Controller/Api/Event/GetEventsController.php` |
| BR-EVT-05 | « Mes événements » = événements des organisations dont l'utilisateur est membre (quel que soit son rôle), brouillons compris. Chemin : `event → organizer → organizer_membership → user`. | Repository | `EventRepository::findByUser()`, `countByUser()` |
| BR-EVT-06 | La recherche par titre est un `LIKE '%…%'` **sensible à la casse** (PostgreSQL). | Repository | `EventRepository::searchEvents()` |
| BR-EVT-07 | La fin d'un événement **devrait** être postérieure à son début : respecté par les fixtures, mais **aucune contrainte** (ni `CHECK`, ni validation) ne l'impose. | Fixtures uniquement | `EventFixtures::buildDates()` |
| BR-EVT-08 | À la création via `POST /api/events`, catégorie / organisation / lieu sont soit liés par `id` (erreur si introuvable), soit **créés** à partir des champs fournis. Les tarifs `ticket_type[]` sont créés et rattachés. | Dénormaliseur | `src/Serializer/Denormalizer/EventInputDenormalizer.php` |
| BR-EVT-09 | `image_url` n'est jamais renseigné par l'API (reste `[]`) et n'est pas exposé. | — | `EventInputDenormalizer`, absence de groupe sur `Event::$imageUrl` |

## 2. Billetterie

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-TKT-01 | Un tarif appartient à exactement un événement ; il est supprimé avec lui ou lorsqu'il est retiré de sa collection. | ORM (`cascade remove`, `orphanRemoval`) | `Event::$ticket_type` |
| BR-TKT-02 | Les prix sont des **entiers en ariary** ; `0` signifie gratuit. | Convention (fixtures) | `EventFixtures::TARIFS`, `TicketType::$prix` (`int`) |
| BR-TKT-03 | La somme des quotas (`quantite_max`) **peut dépasser** la jauge du lieu (`location.size`) : la sur-réservation est un cas testé volontairement, l'alerte étant de la responsabilité de l'interface d'administration. | Aucun contrôle en base | `EventFixtures::createTicketTypes()`, `LocationFixtures` (docblock) |
| BR-TKT-04 | Un événement peut n'avoir **aucun** tarif. | BDD (relation 0..N) | `EventFixtures` (1 sur 12 sans billetterie) |

## 3. Organisations et appartenances

| ID | Règle | Niveau | Source |
|---|---|---|---|
| BR-ORG-01 | Un utilisateur a **au plus un rôle** par organisation. | BDD (unique) + Service | `uniq_membership_user_organizer`, `OrganizerMembershipService::assign()` (idempotent : met à jour le rôle si l'appartenance existe) |
| BR-ORG-02 | Rôles hiérarchiques `owner (30) > admin (20) > member (10)` ; les contrôles s'écrivent « au moins tel niveau ». | Enum | `OrganizerRole::level()`, `isAtLeast()` |
| BR-ORG-03 | **Une organisation garde toujours au moins un responsable** : refus de rétrograder ou de retirer le dernier `owner` (`MembershipException::dernierResponsable`). | Service uniquement | `OrganizerMembershipService::changeRole()`, `revoke()`, `assertNotLastOwner()` |
| <a id="br-org-04"></a>BR-ORG-04 | Le créateur d'une organisation via `POST /api/organizers` en devient responsable **dans la même transaction**. Une organisation créée par le payload imbriqué de `POST /api/events` n'a aucun membre. | Contrôleur (transaction) | `CreateOrganizerController` (`wrapInTransaction`) |
| BR-ORG-05 | Transfert de responsabilité : le nouveau responsable est promu **avant** la rétrogradation des précédents (par défaut en `admin`, ou laissés co-responsables), le tout en un seul `flush`. | Service | `OrganizerMembershipService::transferOwnership()` |
| BR-ORG-06 | Plusieurs responsables simultanés sont autorisés (co-responsables). | Modèle | `Organizer::getOwners()` |
| BR-ORG-07 | Toutes les écritures d'appartenance passent par `OrganizerMembershipService` (API et console `app:organizer:member`). | Convention | `src/Command/OrganizerMemberCommand.php`, contrôleurs `Api/Membership/*` |
| BR-ORG-08 | À la création via l'API : `name` et `email` obligatoires, longueurs max 100/100/50/100, email validé, site validé comme URL, email **non unique**. | Contrôleur | `CreateOrganizerController` |
| BR-ORG-09 | La liste des membres est triée par niveau de rôle décroissant puis par nom (tri fait **en PHP**). | Repository | `OrganizerMembershipRepository::findByOrganizer()` |

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

Un seul statut existe : `event.status` (booléen brouillon / publié). Aucun workflow (Symfony Workflow, machine à états) n'est configuré, et aucun endpoint ne fait passer un événement de brouillon à publié à ce jour (**À confirmer** : publication prévue côté administration ?).
