# Exploitation de la base

Sources : `CLAUDE.md`, `README.md`, `compose.yaml`, `compose.override.yaml`, `config/packages/doctrine*.yaml`, `src/DataFixtures/`, `src/Command/`.

> ⚠️ Les commandes marquées **destructives** modifient ou purgent la base. Elles sont listées pour l'équipe ; aucune n'a été exécutée pendant la rédaction de cette documentation.

## 1. Démarrer la base locale

```bash
docker compose up -d database            # PostgreSQL 16 (postgres:16-alpine)
docker compose port database 5432        # port hôte attribué dynamiquement (compose.override.yaml)
```

- Identifiants par défaut (`compose.yaml`) : base `app`, utilisateur `app`, mot de passe `!ChangeMe!` — surchargeables par `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`.
- La `DATABASE_URL` réelle se place dans `.env.local` (non versionné), avec `serverVersion=16&charset=utf8` (le `server_version` est commenté dans `doctrine.yaml`).
- Les données persistent dans le volume Docker nommé `database_data`.

## 2. Migrations

```bash
php bin/console doctrine:migrations:status          # lecture : état des versions
php bin/console doctrine:migrations:list            # lecture : liste détaillée
php bin/console doctrine:migrations:migrate         # DESTRUCTIF possible : applique les migrations en attente
php bin/console doctrine:migrations:diff            # génère une migration à partir de l'écart entités ↔ base
```

- Sur une **base vierge**, `doctrine:migrations:migrate` crée désormais tout le schéma (`Version20260814185842`). L'indication contraire de `CLAUDE.md` date d'avant le commit `0d5acac`.
- Sur une base **déjà créée** hors migrations (ancien fonctionnement), marquer la version comme exécutée au lieu de la jouer :

  ```bash
  php bin/console doctrine:migrations:version 'DoctrineMigrations\Version20260814185842' --add
  ```

  (et retirer l'ancienne version si elle apparaît en « Executed Unavailable » : `doctrine:migrations:version 'DoctrineMigrations\Version20260108164208' --delete`). Voir [`migrations-history.md`](migrations-history.md).
- Après modification d'une entité : `doctrine:migrations:diff`, relire le SQL généré (supprimer un éventuel `CREATE SCHEMA public` dans `down()`), puis `migrate`.

### Contrôles en lecture seule

```bash
php bin/console doctrine:mapping:info
php bin/console doctrine:schema:validate
php bin/console doctrine:schema:update --dump-sql   # affiche le SQL sans l'exécuter (jamais --force)
```

## 3. Fixtures (données de démonstration)

```bash
php bin/console doctrine:fixtures:load              # DESTRUCTIF : purge toutes les tables, dont "user" et refresh_tokens
```

Ordre de chargement (dépendances `DependentFixtureInterface`) :

```
CategoryFixtures ─┐
LocationFixtures ─┼─► EventFixtures
OrganizerFixtures ┤
                  └─► MembershipFixtures ◄── UserFixtures
```

| Fixture | Contenu | Source |
|---|---|---|
| `CategoryFixtures` | 12 catégories fixes avec couleur | `src/DataFixtures/CategoryFixtures.php` |
| `LocationFixtures` | 12 lieux d'Antananarivo, jauges de 250 à 22 000 | `src/DataFixtures/LocationFixtures.php` |
| `OrganizerFixtures` | 8 organisations | `src/DataFixtures/OrganizerFixtures.php` |
| `UserFixtures` | Comptes de démonstration, mot de passe commun `UserFixtures::PASSWORD`, dont un `ROLE_SUPER_ADMIN` sans organisation | `src/DataFixtures/UserFixtures.php` |
| `MembershipFixtures` | Appartenances : une personne responsable de deux organisations, une équipe complète, une co-responsable, des organisations sans membre | `src/DataFixtures/MembershipFixtures.php` |
| `EventFixtures` | 60 événements (Faker `fr_FR`, `seed(2026)` : jeu reproductible) : passés / en cours / à venir, brouillons, 5 sans billetterie, sur-réservations, tarifs gratuits, prix arrondis à 500 Ar | `src/DataFixtures/EventFixtures.php` |

> Au 2026-11-20, `UserFixtures.php` et `MembershipFixtures.php` ont des **modifications non commitées** : les emails et le mot de passe de la copie de travail ne correspondent plus au tableau des comptes de `CLAUDE.md`. Se référer au code de la branche utilisée.

### Vérifier sans perdre les données de développement

Charger les fixtures dans une base **jetable** (procédure de `CLAUDE.md`) :

```bash
DATABASE_URL="postgresql://…/ticket-up-check?serverVersion=16&charset=utf8" \
  php bin/console doctrine:database:create && \
  php bin/console doctrine:migrations:migrate --no-interaction && \
  php bin/console doctrine:fixtures:load --no-interaction
```

(`doctrine:schema:create` reste une alternative à `migrations:migrate`.)

## 4. Commandes métier touchant la base

| Commande | Effet | Source |
|---|---|---|
| `app:organizer:member list -o <org>` | Liste les membres d'une organisation | `src/Command/OrganizerMemberCommand.php` |
| `app:organizer:member organizations -u <user>` | Liste les organisations d'un utilisateur | idem |
| `app:organizer:member assign -o <org> -u <user> -r owner\|admin\|member` | Rattache / change le rôle (via `OrganizerMembershipService`) | idem |
| `app:organizer:member revoke -o <org> -u <user>` | Retire un membre (refusé pour le dernier responsable) | idem |
| `app:organizer:member transfer -o <org> -u <user>` | Transfère la responsabilité | idem |
| `app:user:super-admin <email> [--revoke]` | Ajoute / retire `ROLE_SUPER_ADMIN` dans `user.roles` | `src/Command/UserSuperAdminCommand.php` |
| `gesdinet:jwt:clear` | Supprime les refresh tokens expirés (commande du bundle, non planifiée) | `gesdinet/jwt-refresh-token-bundle` |

La console est le seul moyen d'**amorcer** une organisation sans membre (aucun droit API n'existe sans appartenance).

## 5. Requêtes SQL utiles (lecture)

```sql
-- Organisations sans responsable (invariant violé ou organisation créée via POST /api/events)
SELECT o.id, o.name
FROM organizer o
WHERE NOT EXISTS (
  SELECT 1 FROM organizer_membership m
  WHERE m.organizer_id = o.id AND m.role = 'owner'
);

-- Événements dont la fin précède le début (aucune contrainte ne l'empêche)
SELECT id, title, started_at, end_at FROM event WHERE end_at < started_at;

-- Sur-réservation : quotas cumulés supérieurs à la jauge du lieu
SELECT e.id, e.title, l.size, SUM(t.quantite_max) AS quotas
FROM event e
JOIN location l ON l.id = e.location_id
JOIN ticket_type t ON t.event_id = e.id
GROUP BY e.id, e.title, l.size
HAVING SUM(t.quantite_max) > l.size;

-- Refresh tokens orphelins ou expirés
SELECT r.id, r.username, r.valid
FROM refresh_tokens r
LEFT JOIN "user" u ON u.email = r.username
WHERE u.id IS NULL OR r.valid < NOW();
```

## 6. Sauvegarde et restauration

**Rien n'est prévu dans le projet** : ni script, ni commande, ni configuration de sauvegarde (**À confirmer** côté infrastructure). À titre indicatif, avec le conteneur Docker local :

```bash
# Sauvegarde (format custom)
docker compose exec -T database pg_dump -U app -d app -Fc > ticketup_$(date +%F).dump

# Restauration dans une base existante (DESTRUCTIF : écrase les objets)
docker compose exec -T database pg_restore -U app -d app --clean --if-exists < ticketup_AAAA-MM-JJ.dump
```

Le `down()` de la migration étant inutilisable (voir [`migrations-history.md`](migrations-history.md)), **la restauration d'une sauvegarde est le seul retour arrière fiable** : faire un `pg_dump` avant toute migration en production.
