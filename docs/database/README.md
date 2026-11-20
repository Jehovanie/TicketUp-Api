# Base de données TicketUp — vue d'ensemble

> Documentation rédigée le 2026-11-20 à partir du code uniquement (entités, configuration, migration, repositories, services, fixtures) et de commandes Doctrine en lecture seule. Toute information non déductible du code est marquée **« À confirmer »** et reprise dans [`open-questions.md`](open-questions.md).

## Objectif métier

La base sert l'API REST TicketUp : catalogue d'**événements** (avec catégorie, lieu, organisation et **types de billets**), gestion des **organisations** et de leurs **membres** (rôles `owner` / `admin` / `member`), et **authentification** des utilisateurs (JWT + refresh tokens).

Source : `README.md` (racine), `src/Entity/*.php`.

Il n'existe **pas encore** de tables de commande, de billet émis, de paiement ou de contrôle d'accès : `ticket_type` décrit une offre tarifaire (nom, prix, quota), pas un billet vendu. Le brouillon `src/sql/main.sql` prévoit ces tables (`order`, `ticket`, `payment`, `ticket_scan`…), mais elles n'ont aucune entité Doctrine — voir [`open-questions.md`](open-questions.md#q-01).

## Pile technique

| Élément | Valeur | Source |
|---|---|---|
| SGBD | PostgreSQL 16 (image `postgres:16-alpine`) | `compose.yaml`, `serverVersion=16` dans `DATABASE_URL` (`.env.local`, non versionné) |
| ORM | Doctrine ORM **3.3.2** | `composer.json` (`^3.3`), `composer show` |
| DBAL | Doctrine DBAL **3.9.4** | `composer.json` (`^3`) |
| Migrations | doctrine/migrations 3.9.0 (bundle 3.4.1) | `composer show` |
| Mapping | Attributs PHP 8 (`type: attribute`), dossier `src/Entity`, préfixe `App\Entity` | `config/packages/doctrine.yaml` |
| Stratégie de nommage | `underscore_number_aware` (`startedAt` → `started_at`) | `config/packages/doctrine.yaml` |
| Connexions | Une seule connexion (`default`) | `config/packages/doctrine.yaml` |
| Framework | Symfony 7.2, API Platform 4 | `composer.json` |
| Bundles touchant la base | `gesdinet/jwt-refresh-token-bundle` (table `refresh_tokens`), `doctrine/doctrine-fixtures-bundle` + `fakerphp/faker` (dev) | `composer.json` |
| Extensions Doctrine | **Aucune** (pas de Gedmo / StofDoctrineExtensions : ni soft delete, ni timestampable automatique, ni sluggable) | `composer.json` |

## Tables

**8 tables métier/techniques mappées** + 1 table technique de Doctrine Migrations.

| Table | Entité | Domaine |
|---|---|---|
| `event` | `App\Entity\Event` | Catalogue |
| `category` | `App\Entity\Category` | Catalogue |
| `location` | `App\Entity\Location` | Catalogue |
| `ticket_type` | `App\Entity\TicketType` | Billetterie |
| `organizer` | `App\Entity\Organizer` | Organisations |
| `organizer_membership` | `App\Entity\OrganizerMembership` | Organisations |
| `"user"` | `App\Entity\User` | Identité & authentification |
| `refresh_tokens` | `App\Entity\RefreshToken` (hérite de `Gesdinet\…\Entity\RefreshToken`) | Identité & authentification |
| `doctrine_migration_versions` | — (technique) | Outillage |

`doctrine:mapping:info` liste 9 entités : la 9ᵉ, `Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken`, est la *MappedSuperclass* du bundle dont hérite `App\Entity\RefreshToken` ; elle ne crée pas de table.

## Domaines fonctionnels

1. **Catalogue** — `event`, `category`, `location` : ce qui est publié et consulté publiquement.
2. **Billetterie** — `ticket_type` : offres tarifaires rattachées à un événement.
3. **Organisations** — `organizer`, `organizer_membership` : qui produit les événements et qui a le droit de les gérer.
4. **Identité & authentification** — `"user"`, `refresh_tokens`.

## État constaté de la base locale (2026-11-20)

| Commande (lecture seule) | Résultat |
|---|---|
| `doctrine:mapping:info` | 9 entités, toutes `[OK]` |
| `doctrine:schema:validate` | Mapping correct, **schéma synchronisé** avec la base |
| `doctrine:migrations:status` | 1 migration disponible, 1 exécutée (`Version20260814185842`, exécutée le 2026-11-17 14:24:46) |
| `doctrine:schema:update --dump-sql` | « Nothing to update » |

Aucun écart entités ↔ migration ↔ base réelle n'a été détecté sur cette base. En revanche, l'historique des migrations a été **réécrit** (voir [`migrations-history.md`](migrations-history.md)), et certaines documentations existantes du dépôt sont devenues inexactes (voir [`open-questions.md`](open-questions.md#q-02)).

## Index de la documentation

| Fichier | Contenu |
|---|---|
| [`erd.md`](erd.md) | Diagrammes entité-relation (global + par domaine) |
| [`data-dictionary.md`](data-dictionary.md) | Dictionnaire de données, une section par table |
| [`relations.md`](relations.md) | Tableau récapitulatif des relations (côté propriétaire, cascade, fetch, ON DELETE) |
| [`business-rules.md`](business-rules.md) | Règles métier déduites du code |
| [`conventions.md`](conventions.md) | Conventions de nommage, clés, dates |
| [`security-and-data.md`](security-and-data.md) | Données personnelles, hachage, rôles |
| [`operations.md`](operations.md) | Migrations, fixtures, commandes utiles, sauvegarde |
| [`open-questions.md`](open-questions.md) | Points d'incertitude et anomalies |