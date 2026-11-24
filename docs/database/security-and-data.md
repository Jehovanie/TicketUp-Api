# Sécurité et données personnelles

Sources : `src/Entity/User.php`, `src/Entity/Organizer.php`, `config/packages/security.yaml`, `config/packages/gesdinet_jwt_refresh_token.yaml`, `src/Service/User/UserRegistrationService.php`, `src/Security/Voter/OrganizerVoter.php`.

## 1. Inventaire des données personnelles (RGPD)

| Table | Colonne | Nature | Personne concernée | Sensibilité |
|---|---|---|---|---|
| `"user"` | `email` | Identifiant direct | Utilisateur | Personnelle |
| `"user"` | `firstname`, `lastname` | Identité | Utilisateur | Personnelle |
| `"user"` | `phone` | Coordonnée | Utilisateur | Personnelle |
| `"user"` | `password` | Secret d'authentification (haché) | Utilisateur | **Critique** |
| `"user"` | `language` | Préférence | Utilisateur | Faible |
| `"user"` | `roles` | Habilitations globales | Utilisateur | Sécurité |
| `"user"` | `is_active`, `created_at`, `updated_at` | Métadonnées de compte | Utilisateur | Faible |
| `organizer_membership` | `user_id`, `role`, `created_at` | Appartenance à une organisation, date d'entrée | Utilisateur | Personnelle |
| `ticket_order` | `buyer_email`, `buyer_firstname`, `buyer_lastname`, `buyer_phone`, `user_id` | Coordonnées de l'acheteur **copiées** à la commande, conservées après suppression du compte (pièce comptable) | Acheteur | Personnelle — à anonymiser sur demande d'effacement |
| `ticket` | `code`, `holder_name` | Code d'accès porteur (qui le détient entre) ; nom du porteur | Acheteur / porteur | **Secret** (code) |
| `refresh_tokens` | `username` | Email de l'utilisateur | Utilisateur | Personnelle |
| `refresh_tokens` | `refresh_token` | Secret de session (**en clair**) | Utilisateur | **Critique** |
| `organizer_payment_method` | `phone_number`, `account_name` | Numéro mobile money et **nom du titulaire** : donnée financière, personnelle si l'organisation est une personne physique | Titulaire du compte | Personnelle + financière — jamais exposée publiquement, réservée aux membres de l'organisation ; supprimée avec l'organisation (`ON DELETE CASCADE`) |
| `organizer` | `email`, `phone`, `website` | Coordonnées de contact | Organisation — peut être une personne physique (auto-entrepreneur) : **À confirmer** | Personnelle possible |

Aucune donnée de paiement, d'adresse postale, de date de naissance ou de géolocalisation n'est stockée à ce jour.

Points RGPD à traiter (aucun mécanisme dans le code) :

- **Droit à l'effacement** : pas d'endpoint ni de commande de suppression de compte. Une suppression SQL de `"user"` supprime ses appartenances (`ON DELETE CASCADE`) mais **laisse ses `refresh_tokens`** (lien par email sans FK). Une suppression **par l'ORM** les supprime (`RefreshTokenUserListener`).
- **Durée de conservation** : non définie ; les refresh tokens expirés sont purgés par `gesdinet:jwt:clear`, à planifier côté infrastructure (recommandé : une fois par jour, voir `operations.md`) — **À confirmer**.
- **Exposition** : `GET /api/user/me` renvoie l'utilisateur courant (dont `roles`) ; `GET /api/organizers/{id}/members` expose l'email **et le téléphone** des membres à tous les membres de l'organisation (`MembershipPresenter::member()`).

## 2. Mots de passe

- Hachage : `password_hashers` → `PasswordAuthenticatedUserInterface: 'auto'` (`config/packages/security.yaml`). Avec Symfony 7.2, `auto` choisit l'algorithme recommandé disponible (bcrypt par défaut, ou Argon2id / sodium selon l'environnement) — algorithme effectif **À confirmer** sur le serveur (préfixe des hachés : `$2y$` = bcrypt, `$argon2id$` = Argon2id).
- **Re-hachage progressif** : `UserRepository::upgradePassword()` (implémente `PasswordUpgraderInterface`) met à jour le haché à la connexion si l'algorithme évolue.
- Colonne `VARCHAR(255)` : suffisante pour bcrypt et Argon2id.
- Politique de mot de passe à l'inscription : 4 à 72 caractères (`RegisterDTO`) ; la contrainte `NotCompromisedPassword` y est **commentée**. Elle est présente sur `User::$password`, mais cette propriété contient le **haché**, et la validation de l'entité n'est pas exécutée à l'inscription : elle est sans effet utile.
- En test, coût de hachage minimal (`when@test`).

## 3. Chiffrement

Aucun chiffrement applicatif de colonne n'est en place (pas de bundle de chiffrement, pas de type Doctrine chiffré). Le chiffrement au repos ou en transit (TLS PostgreSQL) relève de l'infrastructure : **À confirmer**. La `DATABASE_URL` du poste de développement est dans `.env.local` (non versionné) ; `compose.yaml` contient un mot de passe par défaut `!ChangeMe!` à surcharger.

## 4. Jetons

| Jeton | Stockage | Durée | Source |
|---|---|---|---|
| Access token JWT | **Non stocké** en base (signé avec la paire de clés `config/jwt/*.pem`) | 15 min (`token_ttl: 900`, `config/packages/lexik_jwt_authentication.yaml`) | LexikJWTAuthenticationBundle |
| Refresh token | Table `refresh_tokens`, valeur en clair, unique | 30 jours (`ttl: 2592000`), usage unique (`single_use: true`) | Gesdinet, `JwtSecurityService::saveRefreshToken()` |

Le rattachement d'un refresh token à son utilisateur se fait par `username = email`. Si l'email change par l'ORM, `RefreshTokenUserListener` reporte le nouvel email sur ses jetons.

Révocation :

| Besoin | Moyen | Effet |
|---|---|---|
| Déconnecter un appareil | `POST /api/auth/logout` `{refresh_token}` (sans access token) | Supprime ce jeton |
| Déconnecter tous les appareils | `POST /api/auth/logout-all` (JWT requis) | Supprime tous les jetons de l'utilisateur |
| Couper un compte (admin) | `DELETE FROM refresh_tokens WHERE username = '…'` | Idem, sans passer par l'API |

Dans tous les cas, les access tokens déjà émis restent valables jusqu'à 15 minutes : un JWT ne se révoque pas.

## 5. Rôles et lien avec l'entité utilisateur

Deux niveaux d'habilitation coexistent :

1. **Rôles globaux Symfony**, stockés dans `"user".roles` (JSON) :
   - `ROLE_USER` : implicite pour tout utilisateur (`User::getRoles()`).
   - `ROLE_SUPER_ADMIN` : fondateur de la plateforme, court-circuite tous les contrôles d'appartenance. Attribué / retiré par `php bin/console app:user:super-admin <email> [--revoke]` (`src/Command/UserSuperAdminCommand.php`). Aucun `role_hierarchy` n'est déclaré dans `security.yaml`.
2. **Rôles par organisation**, stockés dans `organizer_membership.role` (`owner` / `admin` / `member`), évalués par `OrganizerVoter`.

Fournisseur d'utilisateurs : `entity` sur `App\Entity\User`, propriété `email` (`security.yaml`). Pare-feu `login` (`json_login` sur `/api/auth/login`) et `api` (JWT sans état).

## 6. Points d'attention sécurité liés aux données

| Constat | Impact | Source |
|---|---|---|
| `POST /api/events` n'a ni `security` ni `access_control` : création anonyme possible d'événements **et** d'organisations, catégories et lieux imbriqués. | Pollution / intégrité des données | `src/Entity/Event.php` (opération `Post`), `security.yaml` |
| `GET /api/admin/events/{id}` n'a pas de contrôle d'accès visible. | Exposition des quotas de billets de n'importe quel événement, brouillons compris ; un `id` inexistant provoque une erreur (appel de méthode sur `null`) | `src/Entity/Event.php`, `AdminEventDetailsController` |
| `GET /api/events` expose les brouillons (`status = false`). | Fuite d'événements non publiés | `GetEventsController` |
| `access_control` `^/api/user$` ne couvre pas `/api/user/me` ; la route est protégée par `#[IsGranted('ROLE_USER')]` à la place. | Aucun (protégé), mais la règle `access_control` est trompeuse | `security.yaml`, `MeController` |
| Pas de vérification de compte actif (`is_active`). | Impossible de désactiver un compte sans le supprimer | `User.php` |
| Inscription avec email déjà utilisé : la contrainte unique SQL lève une exception non interceptée. | Réponse 500 probable au lieu d'une erreur 4xx — **À confirmer** par appel | `UserRegistrationService`, `RegisterController` |
