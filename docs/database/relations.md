# Récapitulatif des relations

Sources : `src/Entity/*.php` (attributs `ManyToOne` / `OneToMany` / `JoinColumn`), `migrations/Version20260814185842.php` (contraintes FK).

Aucun attribut `fetch:` n'est déclaré : toutes les associations sont en **`LAZY`** (défaut Doctrine). Les chargements anticipés sont faits au cas par cas dans les repositories (`addSelect` + jointure).

## Tableau des associations

Chaque relation physique (une FK) est décrite des deux côtés : le côté **propriétaire** porte la colonne de jointure, le côté **inverse** est une collection.

| # | Entité source (propriété) | Entité cible | Type | Côté | Colonne / `mappedBy` | Nullable | Cascade ORM | orphanRemoval | ON DELETE (SQL) | Fetch |
|---|---|---|---|---|---|---|---|---|---|---|
| R1 | `Event::$category` | `Category` | ManyToOne | **propriétaire** | `event.category_id` | oui | `persist` | — | aucun (`NO ACTION`) | LAZY |
| R1′ | `Category::$events` | `Event` | OneToMany | inverse | `mappedBy: category` | — | aucune | non | — | LAZY |
| R2 | `Event::$organizer` | `Organizer` | ManyToOne | **propriétaire** | `event.organizer_id` | non | aucune | — | aucun (`NO ACTION`) | LAZY |
| R2′ | `Organizer::$events` | `Event` | OneToMany | inverse | `mappedBy: organizer` | — | aucune | non | — | LAZY |
| R3 | `Event::$location` | `Location` | ManyToOne | **propriétaire** | `event.location_id` | non | `persist` | — | aucun (`NO ACTION`) | LAZY |
| R3′ | `Location::$events` | `Event` | OneToMany | inverse | `mappedBy: location` | — | aucune | non | — | LAZY |
| R4 | `TicketType::$event` | `Event` | ManyToOne | **propriétaire** | `ticket_type.event_id` | non | aucune | — | aucun (`NO ACTION`) | LAZY |
| R4′ | `Event::$ticket_type` | `TicketType` | OneToMany | inverse | `mappedBy: event` | — | `persist`, `remove` | **oui** | — | LAZY |
| R5 | `OrganizerMembership::$user` | `User` | ManyToOne | **propriétaire** | `organizer_membership.user_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R5′ | `User::$memberships` | `OrganizerMembership` | OneToMany | inverse | `mappedBy: user` | — | `persist` | **oui** | — | LAZY |
| R6 | `OrganizerMembership::$organizer` | `Organizer` | ManyToOne | **propriétaire** | `organizer_membership.organizer_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R6′ | `Organizer::$memberships` | `OrganizerMembership` | OneToMany | inverse | `mappedBy: organizer` | — | `persist` | **oui** | — | LAZY |
| R7 | `RefreshToken::$username` | `User` (`email`) | *logique* | — | `refresh_tokens.username` | — | — | — | **aucune FK** | — |
| R8 | `TicketOrder::$user` | `User` | ManyToOne (unidirectionnel) | **propriétaire** | `ticket_order.user_id` | oui | aucune | — | **`SET NULL`** | LAZY |
| R9 | `TicketOrder::$event` | `Event` | ManyToOne (unidirectionnel) | **propriétaire** | `ticket_order.event_id` | non | aucune | — | **`RESTRICT`** | LAZY |
| R10 | `OrderItem::$order` | `TicketOrder` | ManyToOne | **propriétaire** | `order_item.order_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R10′ | `TicketOrder::$items` | `OrderItem` | OneToMany | inverse | `mappedBy: order` | — | `persist` | non | — | LAZY |
| R11 | `OrderItem::$ticketType` | `TicketType` | ManyToOne (unidirectionnel) | **propriétaire** | `order_item.ticket_type_id` | non | aucune | — | **`RESTRICT`** | LAZY |
| R12 | `Ticket::$orderItem` | `OrderItem` | ManyToOne | **propriétaire** | `ticket.order_item_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R12′ | `OrderItem::$tickets` | `Ticket` | OneToMany | inverse | `mappedBy: orderItem` | — | `persist` | non | — | LAZY |
| R13 | `OrganizerPaymentMethod::$organizer` | `Organizer` | ManyToOne | **propriétaire** | `organizer_payment_method.organizer_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R13′ | `Organizer::$paymentMethods` | `OrganizerPaymentMethod` | OneToMany | inverse | `mappedBy: organizer` | — | `persist` | **oui** | — | LAZY |

**12 relations physiques** (12 FK) : les 6 du socle, bidirectionnelles, et les 5 de la commande de billets (R8–R12) et 1 pour les comptes mobile money (R13), dont 3 unidirectionnelles pour ne rien ajouter à `User`, `Event` et `TicketType`. Plus **1 relation logique** sans FK. Les listes de commandes chargent lignes et billets en une seconde requête (`TicketOrderRepository::loadDetails()`).

## Ce que produisent les suppressions

Le comportement diffère selon que la suppression passe **par l'ORM** (`$em->remove()`) ou **directement en SQL** : les cascades ORM ne sont pas reflétées par des `ON DELETE` en base, sauf pour `organizer_membership` et `organizer_payment_method`.

| Suppression de… | Via l'ORM | Via SQL direct |
|---|---|---|
| un `event` | ses `ticket_type` sont supprimés (cascade `remove`) — **échec** s'il a des commandes (`RESTRICT` sur `ticket_order.event_id` et `order_item.ticket_type_id`) | **échec** : `ticket_type.event_id` bloque (`NO ACTION`), puis `ticket_order.event_id` (`RESTRICT`) |
| un `ticket_type` | supprimé seul ; **échec** s'il a été commandé (`RESTRICT`) | idem |
| une `category` | **échec** si des événements la référencent (pas de cascade ni de `SET NULL`, alors que la FK est nullable) | **échec** si des événements la référencent |
| un `location` | **échec** s'il accueille des événements (`409` dans l'API) ; supprimé seul sinon | **échec** si des événements le référencent |
| un `organizer` | **échec** s'il porte des événements (`409` dans l'API) ; sinon supprimé avec ses appartenances (orphanRemoval + `ON DELETE CASCADE`) | **échec** si des événements le référencent ; sinon appartenances supprimées par `ON DELETE CASCADE` |
| un `user` | ses commandes **restent** (`user_id` → `NULL`, coordonnées copiées conservées) ; ses appartenances sont supprimées — **sans passer par `OrganizerMembershipService`**, donc sans vérifier l'invariant « au moins un responsable » | appartenances supprimées (`ON DELETE CASCADE`) ; ses `refresh_tokens` **restent** (pas de FK) — supprimés si la suppression passe par l'ORM (`RefreshTokenUserListener`) |
| une `ticket_order` | ses lignes et billets suivent (`CASCADE`) — à ne pas faire : une commande s'annule, elle ne se supprime pas | idem |
| un `organizer_membership` | supprimé seul (normalement via `OrganizerMembershipService::revoke()`) | supprimé seul, sans contrôle d'invariant |

Aucun endpoint de suppression d'événement, de catégorie, de lieu, d'organisation ou d'utilisateur n'existe à ce jour (`src/Controller/`) : ces comportements ne sont atteignables que par du code futur, la console ou le SQL. Ils sont néanmoins à garder en tête (voir [`open-questions.md`](open-questions.md#q-05)). Depuis la correction de Q-05, aucune suppression ne fait disparaître d'événement en cascade : la base refuse, et `src/EventSubscriber/IntegrityConstraintSubscriber.php` traduit ce refus (SQLSTATE `23503` ou `23001`) en réponse `409` enveloppée, sans détail SQL.

## Effets de `orphanRemoval` à connaître

`orphanRemoval: true` sur une collection inverse signifie que **retirer** un élément de la collection le **supprime** au prochain `flush()` :

- `$event->removeTicketType($t)` ⇒ `DELETE` du tarif (comportement voulu ; refusé par la base si le tarif a été commandé).
- `$user->removeMembership($m)` / `$organizer->removeMembership($m)` ⇒ `DELETE` de l'appartenance (utilisé par `OrganizerMembershipService::revoke()`).
- `$organizer->removePaymentMethod($m)` ⇒ `DELETE` du compte mobile money. Aucun endpoint ne le fait aujourd'hui ; quand il existera, il devra refuser de retirer le **dernier** compte (BR-ORG-10).

`Location::$events` et `Organizer::$events` n'ont plus d'`orphanRemoval` (Q-05, corrigé) : `removeEvent()` ne fait que retirer l'événement de la collection en mémoire. Pour changer le lieu ou l'organisation d'un événement, passer par `$event->setLocation()` / `setOrganizer()`.

## Cascade `persist` depuis `Event`

Les `ManyToOne` `category` et `location` de `Event` sont en `cascade: ['persist']` pour permettre à `src/Serializer/Denormalizer/EventInputDenormalizer.php` de créer à la volée une catégorie ou un lieu décrits dans le corps de `POST /api/events`. `organizer` n'a plus de cascade (2026-11-24) : l'organisation doit exister (`POST /api/organizers`), ce qui garantit qu'elle a un responsable et un compte mobile money.
