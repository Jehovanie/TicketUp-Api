# Points d'incertitude et anomalies

Classement : **🔴 élevé** (intégrité / perte de données / sécurité), **🟠 moyen** (incohérence, dette, performance), **🟢 faible** (documentation, confort). Chaque point cite sa source.

## A. Points à confirmer avec l'équipe

| ID | Question | Pourquoi | Source |
|---|---|---|---|
| <a id="q-01"></a>Q-01 | *(En partie résolu : commande, lignes et billets implémentés — voir `design-orders.md` ; paiement, remboursement, contrôle à l'entrée restent à faire.)* Les tables du brouillon `src/sql/main.sql` (`order`, `order_item`, `ticket`, `ticket_scan`, `payment`, `refund`, `promo_code`, `favorite_event`, `notification`, traductions…) constituent-elles la feuille de route ? | 22 tables prévues, 8 implémentées ; aucune vente de billet n'est modélisée | `src/sql/main.sql` |
| Q-03 | Quels environnements (recette, production) ont exécuté l'ancienne migration `Version20260108164208` ? | Ils nécessitent un `doctrine:migrations:version --add` avant tout `migrate` | `migrations-history.md` |
| Q-04 | Quel est le fuseau horaire de référence des dates (UTC ou `Indian/Antananarivo`) ? | Colonnes `TIMESTAMP WITHOUT TIME ZONE`, aucun fuseau configuré, CLI en UTC | `conventions.md` |
| Q-06 | Comment un événement passe-t-il de brouillon à publié ? | Aucun code n'écrit `status = true` hors fixtures | `src/Entity/Event.php`, `grep setStatus` |
| Q-07 | `is_active` doit-il bloquer la connexion d'un compte non activé ? | Colonne jamais lue ni renseignée à l'inscription | `src/Entity/User.php` |
| Q-08 | `organizer.email` doit-il être unique ? Une organisation peut-elle être une personne physique (RGPD) ? | Choix volontairement non tranché dans le contrôleur | `CreateOrganizerController::website()` (docblock) |
| Q-09 | Quelle est la politique de sauvegarde, de conservation et de purge (comptes supprimés) ? Planification de `gesdinet:jwt:clear` (refresh tokens expirés) à confirmer côté infrastructure. | Rien dans le dépôt | `operations.md` |
| Q-15 | Algorithme de hachage effectif en production (bcrypt / Argon2id) ? | Hasher `auto` | `config/packages/security.yaml` |
| Q-16 | La création anonyme via `POST /api/events` (événement + organisation/catégorie/lieu imbriqués) est-elle voulue ? | Aucun contrôle d'accès sur l'opération | `src/Entity/Event.php`, `security.yaml` |
| Q-17 | Colonnes exactes de `doctrine_migration_versions` | Gérée par le bundle, non décrite dans le code | — |

## B. Anomalies repérées

### Intégrité et cascades

| ID | Gravité | Constat | Source |
|---|---|---|---|
| <a id="q-05"></a>Q-05 | ✅ | **Corrigé le 2026-11-20** : `orphanRemoval` retiré, `removeEvent()` ne touche plus la base, refus traduits en `409` par `IntegrityConstraintSubscriber` (tests : `tests/Integration/Catalog/EventOwnershipTest.php`). Constat initial : `Location::$events` et `Organizer::$events` sont en `orphanRemoval: true` : supprimer un lieu ou une organisation via l'ORM **supprime tous ses événements et leurs tarifs** ; `removeEvent()` supprime l'événement au lieu de le détacher. Probablement un reliquat de MakerBundle plutôt qu'un choix métier. | `src/Entity/Location.php`, `src/Entity/Organizer.php` |
| Q-18 | 🔴 | L'invariant « au moins un responsable » n'existe **qu'au niveau service** : la suppression d'un `user` (ORM ou SQL `ON DELETE CASCADE`) ou une écriture SQL directe peut laisser une organisation sans responsable. | `OrganizerMembershipService`, migration (FK `ON DELETE CASCADE`) |
| Q-19 | 🟠 | Incohérence ORM ↔ SQL : `ticket_type` est supprimé en cascade par l'ORM mais la FK n'a pas d'`ON DELETE CASCADE` ; inversement `event.category_id` est nullable sans `ON DELETE SET NULL` — supprimer une catégorie utilisée échoue. | `relations.md` |
| Q-20 | 🟠 | *(Les tables de commande et `ticket_type.quantite_reservee` ont désormais leurs CHECK.)* Aucune contrainte de cohérence en base sur le socle : `end_at >= started_at`, `prix >= 0`, `quantite_max > 0`, `location.size > 0`, `role IN ('owner','admin','member')` (pas de `CHECK`). | migration, entités |
| Q-21 | 🟠 | La création imbriquée de `POST /api/events` crée des catégories / lieux sans validation (les organisations n'y sont plus créées) : un objet incomplet (ex. catégorie sans `name`) provoque une violation NOT NULL au `flush` (erreur 500) ; des doublons de noms sont possibles (aucune unicité). | `EventInputDenormalizer` |
| Q-22 | ✅ | **Corrigé le 2026-11-20** : `RefreshTokenUserListener` supprime les jetons d'un compte supprimé et reporte un changement d'email (opérations ORM ; le SQL direct reste non couvert, une FK n'étant pas compatible avec le bundle). Ajout de `POST /api/auth/logout` et `/logout-all`. Constat initial : refresh tokens liés par email sans FK, orphelins si l'email change ou si l'utilisateur est supprimé. | `refresh_tokens`, `gesdinet_jwt_refresh_token.yaml` |
| Q-23 | 🟠 | Inscription : seule la validation du DTO est exécutée ; `UniqueEntity` sur `User` n'est jamais évalué, un email en doublon remonte comme exception SQL (500 probable — à confirmer par appel). | `UserRegistrationService`, `RegisterController` |

### Incohérences entités ↔ migrations ↔ documentation

| ID | Gravité | Constat | Source |
|---|---|---|---|
| <a id="q-02"></a>Q-02 | 🟢 | `CLAUDE.md` et `STRUCTURE_BDD.md` affirment que la migration a un `up()` vide et citent `Version20260108164208`, supprimée depuis le commit `0d5acac` ; la migration actuelle crée tout le schéma. | `CLAUDE.md`, `STRUCTURE_BDD.md`, `migrations/` |
| Q-24 | 🟢 | Le tableau des comptes de démonstration de `CLAUDE.md` ne correspond pas à la copie de travail de `UserFixtures.php` / `MembershipFixtures.php` (modifications non commitées au 2026-11-20). | `git status` |
| Q-25 | 🟢 | `identity_generation_preferences: identity` est configuré, mais les colonnes `id` sont des `SERIAL` (séquences), pas des `IDENTITY`. Sans impact fonctionnel ; à garder en tête lors d'un passage à DBAL 4 (qui génère des `IDENTITY`). | `config/packages/doctrine.yaml`, `information_schema.columns` |
| Q-26 | 🟢 | `src/sql/main.sql` ne correspond pas aux entités (`app_user`, `venue`, `organizer.user_id`…). | `src/sql/main.sql` |
| — | ✅ | Aucun écart entités ↔ base locale : `doctrine:schema:validate` OK, `schema:update --dump-sql` vide. Aucune entité sans migration. | commandes Doctrine |

### Authentification

| ID | Gravité | Constat | Source |
|---|---|---|---|
| Q-34 | ✅ | **Corrigé le 2026-11-20** : `POST /api/auth/refresh` répondait **500** (« controller not callable ») — la route pointait vers le contrôleur `gesdinet.jwtrefreshtoken:refresh`, supprimé en v1 du bundle. Le rafraîchissement passe désormais par l'authenticator `refresh_jwt` du pare-feu `api`. `JwtLoginSuccessSubscriber`, jamais appelé (abonné à un nom d'événement que Lexik ne déclenche pas), a été supprimé. | `config/packages/security.yaml`, `config/routes/gesdinet_jwt_refresh_token.yaml` |

### Colonnes inutilisées ou mal typées

| ID | Gravité | Constat | Source |
|---|---|---|---|
| Q-27 | 🟠 | `event.image_url` : type `array` (sérialisation PHP), **déprécié en DBAL 3 et supprimé en DBAL 4** ; jamais alimenté ni exposé. À migrer en `JSON` ou supprimer. | `src/Entity/Event.php` |
| Q-28 | 🟢 | `user.is_active` : nom de booléen pour une date, type mutable (seule date mutable du modèle avec `refresh_tokens.valid`), jamais utilisé. | `src/Entity/User.php` |
| Q-29 | 🟢 | `updated_at` jamais rafraîchi sur `event`, `category`, `location`, `ticket_type` (pas de `PreUpdate`) ; `organizer` n'est rafraîchi que par `OrganizerUpdater` (`PATCH`), à la main. | entités concernées |
| Q-30 | 🟢 | Monnaie implicite (ariary) : aucune colonne de devise sur `ticket_type` (les commandes, elles, portent `currency = MGA`). | `src/Entity/TicketType.php` |

### Index et performance

| ID | Gravité | Constat | Source |
|---|---|---|---|
| <a id="q-10"></a>Q-10 | 🟠 | Index manquants sur `event` pour les filtres et tris réellement utilisés (d'autant plus utiles avec les filtres de BR-EVT-10 : `title`, `status`, `started_at`, `end_at`, `created_at`) : `status` + `started_at` (recherches publiques), `created_at` (liste, « mes événements »). Envisager `(status, started_at)` et `(created_at)`. | `EventRepository`, `GetEventsController` |
| Q-11 | 🟠 | `title LIKE '%…%'` ne peut utiliser aucun index B-tree et est sensible à la casse ; un index trigramme (`pg_trgm`) avec `ILIKE` serait nécessaire à volume élevé. | `EventRepository::searchEvents()` |
| Q-12 | 🟠 | **N+1** : `searchEvents()` et `findByCategory()` ne joignent pas `category`, `location` ni `ticket_type` (`GET /api/events`, `/events/me` et `/organizers/{id}/events` passent désormais par `findFiltered()`, qui joint catégorie et lieu), tous sérialisés dans `events:lists` : jusqu'à 3 requêtes supplémentaires par événement. Les tarifs (collection) ne sont pas joints. | `GetEventsController`, `EventRepository` |
| Q-13 | 🟠 | `searchEvents()` et `findByCategory()` chargent **tous** les résultats sans `LIMIT` ; la pagination de `SearchEventController` est calculée en PHP (`count($events)`). | `EventRepository`, `SearchEventController` |
| Q-14 | ✅ | **Corrigé le 2026-11-20** (`Version20261120155745` : `idx_refresh_token_username`, `idx_refresh_token_valid`). Constat initial : `refresh_tokens.username` non indexé (recherche des jetons d'un utilisateur, purge) ; `valid` non indexé (purge des jetons expirés). | migration |
| Q-31 | 🟢 | Index redondants sur `organizer_membership` : `IDX_E2DB4746A76ED395 (user_id)` est couvert par l'unique `(user_id, organizer_id)`, `IDX_E2DB4746876C4DDA (organizer_id)` par `(organizer_id, role)`. Générés automatiquement par Doctrine ; coût d'écriture négligeable à ce volume. | migration |
| Q-32 | 🟢 | `User::getMembershipFor()` / `hasRoleIn()` et `Organizer::hasMember()` parcourent la collection complète (chargement lazy) à chaque contrôle du voter. Acceptable tant que les organisations ont peu de membres. | `src/Entity/User.php`, `OrganizerVoter` |
| <a id="q-35"></a>Q-35 | 🟠 | **L'invariant « au moins un compte mobile money » n'est garanti qu'à la création via `POST /api/organizers`** (même limite que Q-18). Sont sans compte : les organisations existantes avant `Version20261124124512` (pas de reprise de données), celles créées par l'ancien payload imbriqué de `POST /api/events` (supprimé le 2026-11-24), et toute écriture SQL directe. Aucun endpoint ne permet encore d'ajouter / modifier / retirer un compte : à créer avant d'ouvrir la vente (lot 2), avec refus de retirer le dernier. | `PaymentMethodInputParser`, `EventInputDenormalizer`, migration |
| Q-36 | 🟢 | Les préfixes opérateur (`MobileMoneyProvider::prefixes()`) reflètent le plan de numérotation actuel (Telma 034/038, Orange 032/037, Airtel 033) ; un nouveau préfixe ou une portabilité des numéros obligerait à les mettre à jour, et à relâcher le contrôle si nécessaire. Le nom du titulaire n'est pas vérifié auprès de l'opérateur. | `src/Enum/MobileMoneyProvider.php` |
| Q-37 | 🟠 | `GET /api/admin/events/{id}` (`AdminEventDetailsController`) est un **brouillon** : renvoie `"hello": "world"` et les seuls quotas des tarifs (aucune vente), n'a **aucun contrôle d'accès** et plante (500) sur un événement inconnu. Il n'a pas pu servir de base aux statistiques, qui passent par `OrganizerStatsService`. À supprimer ou à réécrire avec `OrganizerVoter`. | `src/Controller/Admin/Event/AdminEventDetailsController.php` |
| Q-38 | 🟢 | Statistiques : `ticket_order.paid_at` n'est pas indexé (l'index `idx_order_event_status` couvre le filtre par événement et statut). Négligeable tant que les ventes d'une organisation tiennent en quelques dizaines de milliers de lignes ; sinon envisager `(event_id, status, paid_at)`. La série est datée dans le fuseau d'enregistrement des dates (Q-04). | `OrganizerStatsService`, `TicketOrder` |
| Q-39 | 🟢 | `member-candidates` : un administrateur peut parcourir l'annuaire des utilisateurs par tâtonnement (3 caractères, 10 résultats). Aucune limitation de débit sur l'API ; à ajouter (`framework.rate_limiter`) si l'ouverture des inscriptions le justifie. | `SearchMemberCandidatesController` |
| Q-33 | 🟢 | `OrganizerMembershipRepository::findByOrganizer()` trie en PHP (`usort`) : pas de pagination SQL possible pour la liste des membres. | repository |

### Tables orphelines

- `refresh_tokens` est la seule table sans FK ; c'est voulu par le bundle Gesdinet (lien par identifiant utilisateur).
- Aucune autre table isolée ni inutilisée.
