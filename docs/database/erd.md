# Diagrammes entité-relation

Sources : `src/Entity/*.php`, `migrations/Version20260814185842.php`.

Conventions des diagrammes :

- les noms d'entités sont ceux des **tables** SQL (`USER` désigne la table `"user"`) ;
- `PK` = clé primaire, `FK` = clé étrangère, `UK` = participe à une contrainte d'unicité ;
- la longueur des `varchar` et les particularités figurent dans le commentaire entre guillemets ;
- `||--o{` = un-à-plusieurs (zéro ou plus côté « plusieurs ») ; `|o--o{` = côté « un » optionnel (FK nullable).

## 1. Diagramme global simplifié

```mermaid
erDiagram
    CATEGORY  |o--o{ EVENT                : "classe"
    LOCATION  ||--o{ EVENT                : "accueille"
    ORGANIZER ||--o{ EVENT                : "produit"
    EVENT     ||--o{ TICKET_TYPE          : "propose"
    USER      |o--o{ TICKET_ORDER         : "commande (SET NULL)"
    EVENT     ||--o{ TICKET_ORDER         : "est commande (RESTRICT)"
    TICKET_ORDER ||--|{ ORDER_ITEM        : "contient"
    TICKET_TYPE  ||--o{ ORDER_ITEM        : "est vendu (RESTRICT)"
    ORDER_ITEM   ||--o{ TICKET            : "emet"
    USER      ||--o{ ORGANIZER_MEMBERSHIP : "appartient via"
    ORGANIZER ||--o{ ORGANIZER_MEMBERSHIP : "regroupe via"
    USER      ||..o{ REFRESH_TOKENS       : "lien logique email = username, sans FK"
```

Lecture :

- `event` est la table centrale du catalogue : 3 FK sortantes (`category_id` nullable, `location_id`, `organizer_id`).
- `organizer_membership` matérialise un **many-to-many porté par une entité** entre `user` et `organizer` (avec un rôle). Il n'y a pas de `ManyToMany` Doctrine ni de table de jointure implicite.
- `refresh_tokens` n'a **aucune clé étrangère** : le lien avec `user` est logique (`refresh_tokens.username` contient l'email). Il est représenté en pointillés.

## 2. Domaine Catalogue et Billetterie

```mermaid
erDiagram
    CATEGORY |o--o{ EVENT : "category_id (nullable)"
    LOCATION ||--o{ EVENT : "location_id"
    ORGANIZER ||--o{ EVENT : "organizer_id"
    EVENT ||--o{ TICKET_TYPE : "event_id"

    EVENT {
        int id PK "SERIAL"
        int category_id FK "nullable"
        int organizer_id FK "NOT NULL"
        int location_id FK "NOT NULL"
        varchar title "150, NOT NULL"
        text description "NOT NULL"
        timestamp started_at "NOT NULL, sans fuseau"
        timestamp end_at "NOT NULL, sans fuseau"
        text image_url "NOT NULL, tableau PHP serialise (DC2Type array)"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
        boolean status "NOT NULL, true = publie"
    }

    CATEGORY {
        int id PK "SERIAL"
        varchar name "100, NOT NULL"
        varchar color "50, NOT NULL, code couleur"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
    }

    LOCATION {
        int id PK "SERIAL"
        varchar name "100, NOT NULL"
        int size "NOT NULL, jauge en places"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
    }

    TICKET_TYPE {
        int id PK "SERIAL"
        int event_id FK "NOT NULL"
        varchar name "50, NOT NULL"
        int prix "NOT NULL, ariary"
        int quantite_max "NOT NULL, quota"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
    }

    ORGANIZER {
        int id PK "SERIAL"
        varchar name "100, NOT NULL"
    }
```

`ORGANIZER` est volontairement réduit ici ; son détail est dans le domaine suivant.

## 2 bis. Domaine Commandes

Détail des colonnes et contraintes : [`data-dictionary.md`](data-dictionary.md#ticket_order) et [`design-orders.md`](design-orders.md).

```mermaid
erDiagram
    USER |o--o{ TICKET_ORDER : "user_id, ON DELETE SET NULL"
    EVENT ||--o{ TICKET_ORDER : "event_id, ON DELETE RESTRICT"
    TICKET_ORDER ||--|{ ORDER_ITEM : "order_id, ON DELETE CASCADE"
    TICKET_TYPE ||--o{ ORDER_ITEM : "ticket_type_id, ON DELETE RESTRICT"
    ORDER_ITEM ||--o{ TICKET : "order_item_id, ON DELETE CASCADE"

    TICKET_ORDER {
        int id PK "SERIAL"
        varchar reference UK "20, TU-XXXXXXXX"
        int user_id FK, UK "nullable, UK avec idempotency_key"
        int event_id FK "NOT NULL"
        varchar status "20, CHECK pending paid cancelled expired refunded"
        int total_amount "NOT NULL, ariary, CHECK >= 0"
        char currency "3, MGA"
        varchar buyer_email "180, copie figee"
        varchar idempotency_key UK "64, nullable"
        timestamp expires_at "obligatoire si pending"
        timestamp paid_at "nullable"
        timestamp cancelled_at "nullable"
    }

    ORDER_ITEM {
        int id PK "SERIAL"
        int order_id FK, UK "UK avec ticket_type_id"
        int ticket_type_id FK, UK "UK avec order_id"
        varchar ticket_type_name "50, copie figee"
        int unit_price "CHECK >= 0"
        int quantity "CHECK > 0"
        int line_total "CHECK = unit_price x quantity"
    }

    TICKET {
        int id PK "SERIAL"
        int order_item_id FK "NOT NULL"
        varchar code UK "32, contenu du QR"
        varchar status "20, CHECK valid used cancelled"
        timestamp used_at "obligatoire si used"
    }

    TICKET_TYPE {
        int id PK
        int quantite_max "quota"
        int quantite_reservee "DEFAULT 0, CHECK 0..quantite_max"
    }
```

## 3. Domaine Organisations

```mermaid
erDiagram
    USER ||--o{ ORGANIZER_MEMBERSHIP : "user_id, ON DELETE CASCADE"
    ORGANIZER ||--o{ ORGANIZER_MEMBERSHIP : "organizer_id, ON DELETE CASCADE"

    ORGANIZER {
        int id PK "SERIAL"
        varchar name "100, NOT NULL"
        varchar email "100, NOT NULL, non unique"
        varchar phone "50, nullable"
        varchar website "100, nullable"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
    }

    ORGANIZER_MEMBERSHIP {
        int id PK "SERIAL"
        int user_id FK, UK "NOT NULL, uniq_membership_user_organizer"
        int organizer_id FK, UK "NOT NULL, uniq_membership_user_organizer"
        varchar role "20, NOT NULL, enum owner admin member"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
    }

    USER {
        int id PK "SERIAL"
        varchar email UK "180"
    }
```

Contraintes clés de `organizer_membership` :

- unicité `uniq_membership_user_organizer (user_id, organizer_id)` : un seul rôle par personne et par organisation ;
- index `idx_membership_organizer_role (organizer_id, role)` : compter / lister les responsables d'une organisation.

## 4. Domaine Identité et authentification

```mermaid
erDiagram
    USER ||..o{ REFRESH_TOKENS : "username = email (logique, sans FK)"
    USER ||--o{ ORGANIZER_MEMBERSHIP : "user_id"

    USER {
        int id PK "SERIAL"
        varchar email UK "180, NOT NULL, UNIQ_IDENTIFIER_EMAIL"
        json roles "NOT NULL, roles globaux"
        varchar password "255, NOT NULL, hache"
        varchar firstname "50, NOT NULL"
        varchar lastname "50, NOT NULL"
        varchar phone "30, nullable"
        varchar language "10, nullable, fr par defaut"
        timestamp is_active "nullable, date d activation"
        timestamp created_at "NOT NULL"
        timestamp updated_at "NOT NULL"
    }

    REFRESH_TOKENS {
        int id PK "SERIAL"
        varchar refresh_token UK "128, NOT NULL"
        varchar username "255, NOT NULL, email de l utilisateur"
        timestamp valid "NOT NULL, date d expiration"
    }

    ORGANIZER_MEMBERSHIP {
        int id PK "SERIAL"
        int user_id FK "NOT NULL"
    }
```

## 5. Cardinalités exactes

| Relation | Cardinalité | Mapping Doctrine | Table de jointure |
|---|---|---|---|
| `category` → `event` | 0..1 — 0..N | `ManyToOne` (Event) / `OneToMany` (Category) | non |
| `location` → `event` | 1 — 0..N | `ManyToOne` (Event) / `OneToMany` (Location) | non |
| `organizer` → `event` | 1 — 0..N | `ManyToOne` (Event) / `OneToMany` (Organizer) | non |
| `event` → `ticket_type` | 1 — 0..N | `ManyToOne` (TicketType) / `OneToMany` (Event) | non |
| `user` → `ticket_order` | 0..1 — 0..N | `ManyToOne` unidirectionnel (TicketOrder) | non |
| `event` → `ticket_order` | 1 — 0..N | `ManyToOne` unidirectionnel (TicketOrder) | non |
| `ticket_order` → `order_item` | 1 — 1..N | `ManyToOne` (OrderItem) / `OneToMany` (TicketOrder) | non |
| `ticket_type` → `order_item` | 1 — 0..N | `ManyToOne` unidirectionnel (OrderItem) | non |
| `order_item` → `ticket` | 1 — 0..N | `ManyToOne` (Ticket) / `OneToMany` (OrderItem) | non |
| `user` ↔ `organizer` | N — N | 2 × `ManyToOne` depuis `OrganizerMembership` + 2 × `OneToMany` inverses | **`organizer_membership`** (entité à part entière) |
| `user` → `refresh_tokens` | 1 — 0..N (logique) | aucun | non |

Aucune relation `OneToOne` ni `ManyToMany` Doctrine n'existe dans le projet. Détail des cascades : [`relations.md`](relations.md).
