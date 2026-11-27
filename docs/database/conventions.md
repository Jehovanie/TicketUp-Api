# Conventions observées

Sources : `config/packages/doctrine.yaml`, `src/Entity/*.php`, `migrations/Version20260814185842.php`.

## Mapping

- **Attributs PHP 8** exclusivement (`#[ORM\Entity]`, `#[ORM\Column]`…), aucun fichier XML/YAML de mapping.
- Une entité = une classe dans `src/Entity/`, un repository dédié dans `src/Repository/` (`ServiceEntityRepository`), sauf `RefreshToken` (repository du bundle Gesdinet).
- Pas de `MappedSuperclass`, de trait ni d'`Embeddable` propres au projet : les champs communs (`id`, `createdAt`, `updatedAt`) sont **dupliqués** dans chaque entité. Seule `RefreshToken` hérite d'une superclasse (celle du bundle).
- Code généré majoritairement par MakerBundle (getters/setters fluides `static`, méthodes `add*/remove*`, blocs d'exemple commentés dans les repositories).

## Nommage

| Élément | Convention | Exemple | Exceptions |
|---|---|---|---|
| Table | `snake_case` singulier, dérivé du nom de classe (`underscore_number_aware`) | `ticket_type`, `organizer_membership` | `refresh_tokens` (pluriel, imposé par `#[ORM\Table]`) ; `"user"` (mot réservé, échappé) |
| Colonne | `snake_case` dérivé de la propriété camelCase | `startedAt` → `started_at` | — |
| Propriété PHP | camelCase anglais | `startedAt`, `createdAt` | `TicketType::$prix`, `$quantite_max` (français + snake_case) ; `Event::$ticket_type` (collection en snake_case, au singulier) ; `User::$is_active` (snake_case) |
| Colonne FK | `<relation>_id` | `organizer_id` | — |
| Index / contraintes générés | Hash Doctrine | `IDX_3BAE0AA712469DE2`, `FK_…`, `UNIQ_…` | Nommés à la main : `uniq_membership_user_organizer`, `idx_membership_organizer_role`, `UNIQ_IDENTIFIER_EMAIL` |
| Langue | Majoritairement anglais | `title`, `location` | `prix`, `quantite_max` ; messages et commentaires en français |

## Clés

- **Clés primaires** : entier auto-incrémenté `id` sur toutes les tables (séquence `SERIAL`). Pas d'UUID, pas de clé composite.
- **Clés étrangères** : entières, avec index automatique. `ON DELETE CASCADE` uniquement sur `organizer_membership` ; ailleurs, la suppression en cascade est gérée (ou non) par l'ORM.
- **Unicité métier** : posée explicitement uniquement sur `user.email`, `refresh_tokens.refresh_token`, `organizer_membership (user_id, organizer_id)`.

## Types

| Usage | Type PHP | Type SQL |
|---|---|---|
| Dates métier et horodatage | `\DateTimeImmutable` | `TIMESTAMP(0) WITHOUT TIME ZONE` + commentaire `(DC2Type:datetime_immutable)` |
| Exceptions mutables | `\DateTimeInterface` (`User::$is_active`), `\DateTime` (`RefreshToken::$valid`, bundle) | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| Montants | `int` (ariary, sans décimales) | `INT` |
| Booléen | `bool` | `BOOLEAN` |
| Énumération | enum PHP adossé à une chaîne (`OrganizerRole`) via `enumType` | `VARCHAR(20)`, sans `CHECK` |
| Listes | `array` | `JSON` (`user.roles`, `event.images`) — plus aucun `Types::ARRAY` (sérialisation PHP) depuis `Version20261127122032` |
| Textes courts | `string` | `VARCHAR(n)` avec longueur explicite (50, 100, 150, 180) |

## Dates et fuseaux horaires

- Toutes les colonnes de date sont **sans fuseau horaire** : la base stocke l'heure « murale » telle que PHP la fournit.
- Aucun fuseau n'est configuré dans le projet (pas de `date_default_timezone_set`, pas de `timezone` dans `config/`). Le fuseau effectif est donc celui de `php.ini` : `UTC` en CLI sur le poste analysé ; **À confirmer** pour le serveur web et la production (Madagascar est en UTC+3, `Indian/Antananarivo`).
- Les dates saisies via l'API (`startedAt`, `endAt`) sont construites par `new \DateTimeImmutable($data['startedAt'])` : un décalage horaire fourni par le client est converti… puis **perdu** à l'enregistrement (seule l'heure locale de l'objet est stockée).
- Les réponses formatent parfois sans fuseau (`'Y-m-d H:i:s'` dans `MeController`).

## Horodatage

Deux conventions coexistent (voir [`business-rules.md`](business-rules.md#6-horodatage-automatique)) :

- **lifecycle callbacks** `PrePersist` / `PreUpdate` (`User`, `OrganizerMembership`) — la plus récente, correcte ;
- **constructeur** (`Event`, `Category`, `Location`, `Organizer`, `TicketType`) — `updated_at` n'est jamais rafraîchi.

## Sérialisation liée au modèle

Les groupes Symfony Serializer sont posés sur les propriétés des entités (`events:lists`, `events:details`, `events:create`, `category:*`, `location:*`, `organizer:*`, `membership:lists`). Ils définissent ce qui sort de la base vers l'API ; `events:create` est documentaire seulement (le dénormaliseur personnalisé construit l'entité champ par champ).
