# Récapitulatif des relations

Sources : `src/Entity/*.php` (attributs `ManyToOne` / `OneToMany` / `JoinColumn`), `migrations/Version20260814185842.php` (contraintes FK).

Aucun attribut `fetch:` n'est déclaré : toutes les associations sont en **`LAZY`** (défaut Doctrine). Les chargements anticipés sont faits au cas par cas dans les repositories (`addSelect` + jointure).

## Tableau des associations

Chaque relation physique (une FK) est décrite des deux côtés : le côté **propriétaire** porte la colonne de jointure, le côté **inverse** est une collection.

| # | Entité source (propriété) | Entité cible | Type | Côté | Colonne / `mappedBy` | Nullable | Cascade ORM | orphanRemoval | ON DELETE (SQL) | Fetch |
|---|---|---|---|---|---|---|---|---|---|---|
| R1 | `Event::$category` | `Category` | ManyToOne | **propriétaire** | `event.category_id` | oui | `persist` | — | aucun (`NO ACTION`) | LAZY |
| R1′ | `Category::$events` | `Event` | OneToMany | inverse | `mappedBy: category` | — | aucune | non | — | LAZY |
| R2 | `Event::$organizer` | `Organizer` | ManyToOne | **propriétaire** | `event.organizer_id` | non | `persist` | — | aucun (`NO ACTION`) | LAZY |
| R2′ | `Organizer::$events` | `Event` | OneToMany | inverse | `mappedBy: organizer` | — | aucune | **oui** | — | LAZY |
| R3 | `Event::$location` | `Location` | ManyToOne | **propriétaire** | `event.location_id` | non | `persist` | — | aucun (`NO ACTION`) | LAZY |
| R3′ | `Location::$events` | `Event` | OneToMany | inverse | `mappedBy: location` | — | aucune | **oui** | — | LAZY |
| R4 | `TicketType::$event` | `Event` | ManyToOne | **propriétaire** | `ticket_type.event_id` | non | aucune | — | aucun (`NO ACTION`) | LAZY |
| R4′ | `Event::$ticket_type` | `TicketType` | OneToMany | inverse | `mappedBy: event` | — | `persist`, `remove` | **oui** | — | LAZY |
| R5 | `OrganizerMembership::$user` | `User` | ManyToOne | **propriétaire** | `organizer_membership.user_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R5′ | `User::$memberships` | `OrganizerMembership` | OneToMany | inverse | `mappedBy: user` | — | `persist` | **oui** | — | LAZY |
| R6 | `OrganizerMembership::$organizer` | `Organizer` | ManyToOne | **propriétaire** | `organizer_membership.organizer_id` | non | aucune | — | **`CASCADE`** | LAZY |
| R6′ | `Organizer::$memberships` | `OrganizerMembership` | OneToMany | inverse | `mappedBy: organizer` | — | `persist` | **oui** | — | LAZY |
| R7 | `RefreshToken::$username` | `User` (`email`) | *logique* | — | `refresh_tokens.username` | — | — | — | **aucune FK** | — |

**6 relations physiques** (6 FK), toutes bidirectionnelles (12 côtés d'association mappés), et **1 relation logique** sans FK.

## Ce que produisent les suppressions

Le comportement diffère selon que la suppression passe **par l'ORM** (`$em->remove()`) ou **directement en SQL** : les cascades ORM ne sont pas reflétées par des `ON DELETE` en base, sauf pour `organizer_membership`.

| Suppression de… | Via l'ORM | Via SQL direct |
|---|---|---|
| un `event` | ses `ticket_type` sont supprimés (cascade `remove`) | **échec** : `ticket_type.event_id` bloque (`NO ACTION`) |
| un `ticket_type` | supprimé seul | supprimé seul |
| une `category` | **échec** si des événements la référencent (pas de cascade ni de `SET NULL`, alors que la FK est nullable) | **échec** si des événements la référencent |
| un `location` | **tous ses événements sont supprimés**, et leurs tarifs (orphanRemoval ⇒ cascade remove) | **échec** si des événements le référencent |
| un `organizer` | **tous ses événements et leurs tarifs sont supprimés** ; ses appartenances aussi (orphanRemoval + `ON DELETE CASCADE`) | **échec** si des événements le référencent ; sinon appartenances supprimées par `ON DELETE CASCADE` |
| un `user` | ses appartenances sont supprimées — **sans passer par `OrganizerMembershipService`**, donc sans vérifier l'invariant « au moins un responsable » | appartenances supprimées (`ON DELETE CASCADE`) ; ses `refresh_tokens` **restent** (pas de FK) |
| un `organizer_membership` | supprimé seul (normalement via `OrganizerMembershipService::revoke()`) | supprimé seul, sans contrôle d'invariant |

Aucun endpoint de suppression d'événement, de catégorie, de lieu, d'organisation ou d'utilisateur n'existe à ce jour (`src/Controller/`) : ces comportements ne sont atteignables que par du code futur, la console ou le SQL. Ils sont néanmoins à garder en tête (voir [`open-questions.md`](open-questions.md#q-05)).

## Effets de `orphanRemoval` à connaître

`orphanRemoval: true` sur une collection inverse signifie que **retirer** un élément de la collection le **supprime** au prochain `flush()` :

- `$location->removeEvent($event)` ou `$organizer->removeEvent($event)` ⇒ `DELETE` de l'événement, et non détachement. Les méthodes générées par MakerBundle appellent pourtant `$event->setLocation(null)` / `setOrganizer(null)`, ce qui serait de toute façon refusé (colonnes NOT NULL).
- `$event->removeTicketType($t)` ⇒ `DELETE` du tarif (comportement voulu).
- `$user->removeMembership($m)` / `$organizer->removeMembership($m)` ⇒ `DELETE` de l'appartenance (utilisé par `OrganizerMembershipService::revoke()`).

## Cascade `persist` depuis `Event`

Les trois `ManyToOne` de `Event` sont en `cascade: ['persist']` pour permettre à `src/Serializer/Denormalizer/EventInputDenormalizer.php` de créer à la volée une catégorie, une organisation ou un lieu décrits dans le corps de `POST /api/events`. Conséquence : une organisation créée ainsi n'a **aucun membre** (aucune ligne `organizer_membership`) — voir [`business-rules.md`](business-rules.md#br-org-04).
