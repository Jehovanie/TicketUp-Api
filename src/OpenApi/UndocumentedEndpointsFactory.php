<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documente dans `/api/docs` les routes qui n'y figuraient pas.
 *
 * API Platform ne décrit que ce qu'il route lui-même : les opérations déclarées
 * dans un `#[ApiResource]`. Or une bonne partie de l'API est écrite en
 * contrôleurs Symfony classiques — authentification, profil, création
 * d'organisation, gestion des membres — parce que ce sont des écritures à corps
 * libre qui ne correspondent à aucune ressource. Ces routes existent bel et
 * bien (`php bin/console debug:router` les liste) mais restaient invisibles
 * dans la documentation, donc introuvables pour les applications admin et
 * mobile.
 *
 * Ce décorateur n'ajoute que de la description : il reçoit le document généré
 * et y insère des chemins. Aucun contrôleur, aucune route, aucun contrôle
 * d'accès n'est touché — une erreur ici se voit dans `/api/docs`, jamais dans
 * le comportement de l'API. En contrepartie, c'est de la documentation écrite à
 * la main : elle doit être mise à jour avec les contrôleurs correspondants.
 *
 * `/api/auth/login` n'est pas repris ici : le bundle Lexik le documente déjà
 * (il décore la même factory).
 */
#[AsDecorator('api_platform.openapi.factory')]
final class UndocumentedEndpointsFactory implements OpenApiFactoryInterface
{
    private const TAG_AUTH = 'Authentification';
    private const TAG_SANTE = 'Santé';
    private const TAG_UTILISATEUR = 'Utilisateur';
    private const TAG_MEMBRES = 'Membres d’une organisation';

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $openApi = $this->avecSchemas($openApi);

        // `Paths::addPath()` modifie l'objet sur place — contrairement aux
        // `with*()` du reste du modèle, qui renvoient un clone.
        $paths = $openApi->getPaths();

        $paths->addPath('/api/health', new PathItem(get: $this->health()));

        $paths->addPath('/api/auth/register', new PathItem(post: $this->register()));
        $paths->addPath('/api/auth/refresh', new PathItem(post: $this->refresh()));

        $paths->addPath('/api/user/me', new PathItem(get: $this->me()));
        $paths->addPath('/api/user/me/organizations', new PathItem(get: $this->mesOrganisations()));

        // `/api/organizers` est déjà documenté en lecture (opération de
        // `#[ApiResource]`) : on complète l'entrée existante plutôt que de la
        // remplacer, sinon le `GET` disparaîtrait de la documentation.
        $organizers = $paths->getPath('/api/organizers') ?? new PathItem();
        $paths->addPath('/api/organizers', $organizers->withPost($this->creerOrganisation()));

        $paths->addPath('/api/organizers/{organizerId}/members', new PathItem(
            get: $this->listerMembres(),
            post: $this->ajouterMembre(),
        ));

        $paths->addPath('/api/organizers/{organizerId}/members/{userId}', new PathItem(
            put: $this->changerRole('put'),
            delete: $this->retirerMembre(),
            patch: $this->changerRole('patch'),
        ));

        $paths->addPath('/api/organizers/{organizerId}/owner', new PathItem(
            put: $this->transfererResponsabilite(),
        ));

        return $openApi->withTags(array_merge($openApi->getTags(), [
            new Tag(self::TAG_AUTH, 'Inscription, connexion et renouvellement des jetons.'),
            new Tag(self::TAG_UTILISATEUR, 'Profil et rattachements de l’utilisateur connecté.'),
            new Tag(
                self::TAG_MEMBRES,
                'Rattachement des utilisateurs aux organisations et gestion de leurs rôles. '
                . 'Les droits découlent du rôle dans l’organisation visée (voir `OrganizerVoter`) : '
                . 'appartenir à une organisation ne donne aucun droit sur une autre.'
            ),
            new Tag(self::TAG_SANTE, 'Supervision du service.'),
        ]));
    }

    // ------------------------------------------------------------- Santé

    private function health(): Operation
    {
        return new Operation(
            operationId: 'api_health_check',
            tags: [self::TAG_SANTE],
            responses: [
                '200' => new Response('Le service répond.', $this->contenu(
                    [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            // Chaîne, et non entier comme dans l'enveloppe du
                            // reste de l'API : c'est la réponse réelle.
                            'status' => ['type' => 'string', 'example' => 'OK'],
                        ],
                    ],
                    ['message' => 'Service is up and running', 'status' => 'OK']
                )),
            ],
            summary: 'Sonde de disponibilité',
            description: "Répond `200` dès que PHP et le noyau Symfony démarrent. **La sonde ne vérifie ni la base "
                . "de données ni les clés JWT** : une base injoignable la laisse verte. Accessible sans jeton.\n\n"
                . "Noter que `status` vaut ici la chaîne `\"OK\"`, et non un entier comme dans l'enveloppe "
                . "`{message, status, data}` du reste de l'API.",
        );
    }

    // --------------------------------------------------- Authentification

    private function register(): Operation
    {
        return new Operation(
            operationId: 'api_register',
            tags: [self::TAG_AUTH],
            responses: [
                '201' => new Response('Compte créé, jetons délivrés.', $this->contenu([
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string'],
                        'refresh_token' => ['type' => 'string'],
                        'user' => ['$ref' => '#/components/schemas/CompteUtilisateur'],
                    ],
                ])),
                '500' => new Response(
                    'Données invalides. Oui, `500` : le service lève une `\InvalidArgumentException` '
                    . 'qui n’est pas interceptée — un email déjà utilisé remonte de même, en violation '
                    . 'de contrainte d’unicité.'
                ),
            ],
            summary: 'Créer un compte',
            description: "Crée le compte **et** délivre directement un couple access token / refresh token : "
                . "l'inscription n'a pas besoin d'être suivie d'un appel à `/api/auth/login`.\n\n"
                . "`email`, `password` (4 à 72 caractères), `firstname` et `lastname` sont obligatoires ; "
                . "`phone` et `language` sont facultatifs. Le compte reçoit `ROLE_USER`.",
            requestBody: new RequestBody('Informations du compte à créer', $this->contenu(
                [
                    'type' => 'object',
                    'required' => ['email', 'password', 'firstname', 'lastname'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'password' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 72],
                        'firstname' => ['type' => 'string', 'maxLength' => 50],
                        'lastname' => ['type' => 'string', 'maxLength' => 50],
                        'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
                        'language' => ['type' => 'string', 'maxLength' => 10, 'nullable' => true],
                    ],
                ],
                [
                    'email' => 'jean@example.com',
                    'password' => 'motdepasse',
                    'firstname' => 'Jean',
                    'lastname' => 'Dupont',
                    'phone' => '0341234567',
                    'language' => 'fr',
                ]
            ), true),
        );
    }

    private function refresh(): Operation
    {
        return new Operation(
            operationId: 'gesdinet_jwt_refresh_token',
            tags: [self::TAG_AUTH],
            responses: [
                '200' => new Response('Nouveau couple de jetons.', $this->contenu([
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string'],
                        'refresh_token' => ['type' => 'string'],
                    ],
                ])),
                '401' => new Response('Refresh token inconnu, déjà consommé ou expiré.'),
            ],
            summary: 'Renouveler l’access token',
            description: "Fourni par `gesdinet/jwt-refresh-token-bundle`. La configuration est en "
                . "`single_use: true` : **le refresh token est consommé à chaque appel** et remplacé par un "
                . "nouveau, qu'il faut donc enregistrer côté client. Durée de vie : 30 jours "
                . "(access token : 15 minutes).",
            requestBody: new RequestBody('Refresh token à échanger', $this->contenu(
                [
                    'type' => 'object',
                    'required' => ['refresh_token'],
                    'properties' => ['refresh_token' => ['type' => 'string']],
                ],
                ['refresh_token' => 'a1b2c3…']
            ), true),
        );
    }

    // ------------------------------------------------------- Utilisateur

    private function me(): Operation
    {
        return new Operation(
            operationId: 'api_me',
            tags: [self::TAG_UTILISATEUR],
            responses: [
                '200' => new Response('Profil de l’utilisateur connecté.', $this->contenu(
                    ['$ref' => '#/components/schemas/ProfilUtilisateur'],
                    [
                        'id' => 1,
                        'email' => 'jean@example.com',
                        'firstname' => 'Jean',
                        'lastname' => 'Dupont',
                        'phone' => '0341234567',
                        'language' => 'fr',
                        'roles' => ['ROLE_USER'],
                        'createdAt' => '2026-01-08 16:42:08',
                        'updatedAt' => '2026-01-08 16:42:08',
                    ]
                )),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
            ],
            summary: 'Mon profil',
            description: "Objet **plat**, construit à la main dans le contrôleur : contrairement au reste de "
                . "l'API, la réponse n'est pas enveloppée dans `{message, status, data}`.",
            security: $this->jwt(),
        );
    }

    private function mesOrganisations(): Operation
    {
        return new Operation(
            operationId: 'api_me_organizations',
            tags: [self::TAG_UTILISATEUR],
            responses: [
                '200' => new Response('Organisations de l’utilisateur connecté.', $this->contenu([
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'status' => ['type' => 'integer', 'example' => 200],
                        'data' => [
                            'type' => 'object',
                            'properties' => [
                                'itemsTotal' => ['type' => 'integer'],
                                'isSuperAdmin' => ['type' => 'boolean'],
                                'items' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/OrganisationUtilisateur'],
                                ],
                            ],
                        ],
                    ],
                ])),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
            ],
            summary: 'Mes organisations (vue complète)',
            description: "Organisations auxquelles l'utilisateur connecté appartient, avec son rôle dans "
                . "chacune, leurs coordonnées, la date d'entrée et le drapeau `isSuperAdmin`. **Sans "
                . "pagination** : `data` ne porte donc ni `currentPage` ni `nombreParPage`. Triées par nom.\n\n"
                . "`GET /api/organizers/me` répond à la même question en vue réduite et paginée, faite pour un "
                . "sélecteur d'organisation.\n\n"
                . "`ROLE_SUPER_ADMIN` ouvre tous les droits mais ne crée aucune appartenance : un super "
                . "administrateur membre de rien reçoit `items: []`.",
            security: $this->jwt(),
        );
    }

    // -------------------------------------------- Création d'organisation

    private function creerOrganisation(): Operation
    {
        return new Operation(
            operationId: 'api_organizer_create',
            tags: ['Organizer'],
            responses: [
                '201' => new Response(
                    'Organisation créée, le créateur en est responsable.',
                    $this->contenu($this->enveloppe(['$ref' => '#/components/schemas/OrganisationUtilisateur'], 201))
                ),
                '400' => $this->erreurMetier(
                    'Saisie refusée : champ obligatoire vide, longueur au-delà de la colonne, '
                    . 'email ou URL malformés.'
                ),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
            ],
            summary: 'Créer une organisation',
            description: "Seule façon de créer une organisation **gérable** : le créateur y est rattaché comme "
                . "responsable dans la même transaction. Une organisation créée par le payload imbriqué de "
                . "`POST /api/events` n'a aucun membre — elle n'apparaît donc dans aucun `/me` et personne n'a "
                . "de droits dessus.\n\n"
                . "`name` et `email` sont obligatoires ; `phone` et `website` facultatifs. L'email est "
                . "normalisé en minuscules et **n'est pas unique** en base : deux organisations peuvent "
                . "partager une adresse.",
            requestBody: new RequestBody('Informations de l’organisation', $this->contenu(
                [
                    'type' => 'object',
                    'required' => ['name', 'email'],
                    'properties' => [
                        'name' => ['type' => 'string', 'maxLength' => 100],
                        'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 100],
                        'phone' => ['type' => 'string', 'maxLength' => 50, 'nullable' => true],
                        'website' => [
                            'type' => 'string',
                            'format' => 'uri',
                            'maxLength' => 100,
                            'nullable' => true,
                            'example' => 'https://antsika.mg',
                        ],
                    ],
                ],
                [
                    'name' => 'Antsika Prod',
                    'email' => 'contact@antsika.mg',
                    'phone' => '+261 34 00 000 00',
                    'website' => 'https://antsika.mg',
                ]
            ), true),
            security: $this->jwt(),
        );
    }

    // ----------------------------------------------------------- Membres

    private function listerMembres(): Operation
    {
        return new Operation(
            operationId: 'api_organizer_members',
            tags: [self::TAG_MEMBRES],
            responses: [
                '200' => new Response('Membres de l’organisation.', $this->contenu([
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'status' => ['type' => 'integer', 'example' => 200],
                        'data' => [
                            'type' => 'object',
                            'properties' => [
                                'organizer' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'integer'],
                                        'name' => ['type' => 'string'],
                                    ],
                                ],
                                'itemsTotal' => ['type' => 'integer'],
                                'items' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Membre'],
                                ],
                            ],
                        ],
                    ],
                ])),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '403' => $this->accesRefuse(),
                '404' => $this->erreurMetier('Organisation introuvable.'),
            ],
            summary: 'Lister les membres',
            description: "Droit requis : **VIEW** (membre ou plus de cette organisation, ou "
                . "`ROLE_SUPER_ADMIN`).\n\n"
                . "Membres **responsables en tête** : tri par niveau de rôle décroissant puis par nom de "
                . "famille. Pas de pagination.",
            parameters: [$this->organizerIdParam()],
            security: $this->jwt(),
        );
    }

    private function ajouterMembre(): Operation
    {
        return new Operation(
            operationId: 'api_organizer_member_add',
            tags: [self::TAG_MEMBRES],
            responses: [
                '201' => new Response(
                    'Utilisateur rattaché (ou rôle mis à jour s’il l’était déjà).',
                    $this->contenu($this->enveloppe(['$ref' => '#/components/schemas/Membre'], 201))
                ),
                '400' => $this->erreurMetier('Rôle inconnu. Valeurs acceptées : `owner`, `admin`, `member`.'),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '403' => $this->accesRefuse(),
                '404' => $this->erreurMetier(
                    'Organisation ou utilisateur introuvable — y compris quand ni `userId` ni `email` '
                    . 'n’est fourni.'
                ),
            ],
            summary: 'Rattacher un utilisateur',
            description: "Droit requis : **MANAGE_MEMBERS** (administrateur ou plus). Désigner un `owner` "
                . "demande en plus **TRANSFER_OWNERSHIP** : seul un responsable peut en nommer un autre.\n\n"
                . "L'utilisateur se désigne par `userId` **ou** par `email`, au choix de l'appelant. `role` "
                . "est facultatif et vaut `member` par défaut.\n\n"
                . "Opération **idempotente** : rejouer l'appel sur un membre existant met simplement son rôle "
                . "à jour — toujours `201`, jamais de doublon (l'unicité (utilisateur, organisation) "
                . "l'interdit).",
            parameters: [$this->organizerIdParam()],
            requestBody: new RequestBody('Utilisateur à rattacher et rôle à lui donner', $this->contenu(
                [
                    'type' => 'object',
                    'properties' => [
                        'userId' => ['type' => 'integer', 'description' => 'Alternative à `email`.'],
                        'email' => [
                            'type' => 'string',
                            'format' => 'email',
                            'description' => 'Alternative à `userId`.',
                        ],
                        'role' => $this->roleSchema('member'),
                    ],
                ],
                ['email' => 'mamy.rabe@techmada.mg', 'role' => 'admin']
            ), true),
            security: $this->jwt(),
        );
    }

    /**
     * La route accepte `PATCH` et `PUT` : une seule opération, décrite deux fois
     * pour que la documentation reflète la route telle qu'elle est déclarée.
     */
    private function changerRole(string $verbe): Operation
    {
        $avertissementCors = 'patch' === $verbe
            ? "\n\n⚠️ `PATCH` est absent d'`allow_methods` dans `config/packages/nelmio_cors.yaml` : depuis un "
                . "navigateur la requête préalable (préflight) échoue. **Utiliser `PUT`**, que cette même route "
                . "accepte, ou ajouter `PATCH` à la configuration CORS."
            : '';

        return new Operation(
            operationId: 'api_organizer_member_update_' . $verbe,
            tags: [self::TAG_MEMBRES],
            responses: [
                '200' => new Response(
                    'Rôle mis à jour.',
                    $this->contenu($this->enveloppe(['$ref' => '#/components/schemas/Membre']))
                ),
                '400' => $this->erreurMetier('`role` absent ou inconnu — il est obligatoire ici.'),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '403' => $this->accesRefuse(),
                '404' => $this->erreurMetier(
                    'Organisation ou utilisateur introuvable, ou la personne ne fait pas partie '
                    . 'de cette organisation.'
                ),
                '409' => $this->erreurMetier(
                    'L’organisation doit conserver au moins un responsable : rétrograder le dernier est '
                    . 'refusé. Désignez d’abord un nouveau responsable.'
                ),
            ],
            summary: 'Changer le rôle d’un membre',
            description: "Droit requis : **MANAGE_MEMBERS**, plus **TRANSFER_OWNERSHIP** dès qu'un responsable "
                . "est concerné — en promotion (`role: \"owner\"`) comme en rétrogradation (la cible est déjà "
                . "responsable).\n\n"
                . "`role` est **obligatoire** ici, contrairement au rattachement. Un rôle identique à l'actuel "
                . "est accepté sans écriture."
                . $avertissementCors,
            parameters: [$this->organizerIdParam(), $this->userIdParam()],
            requestBody: new RequestBody('Nouveau rôle', $this->contenu(
                [
                    'type' => 'object',
                    'required' => ['role'],
                    'properties' => ['role' => $this->roleSchema()],
                ],
                ['role' => 'member']
            ), true),
            security: $this->jwt(),
        );
    }

    private function retirerMembre(): Operation
    {
        return new Operation(
            operationId: 'api_organizer_member_remove',
            tags: [self::TAG_MEMBRES],
            responses: [
                '200' => new Response('Membre retiré.', $this->contenu($this->enveloppe(['nullable' => true]))),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '403' => $this->accesRefuse(),
                '404' => $this->erreurMetier(
                    'Organisation ou utilisateur introuvable, ou la personne ne fait pas partie '
                    . 'de cette organisation.'
                ),
                '409' => $this->erreurMetier(
                    'L’organisation doit conserver au moins un responsable : retirer le dernier est refusé.'
                ),
            ],
            summary: 'Retirer un membre',
            description: "Droit requis : **MANAGE_MEMBERS**, plus **TRANSFER_OWNERSHIP** si la cible est "
                . "responsable.\n\n"
                . "Supprime l'appartenance, donc le rôle — **pas le compte** de la personne, ni les événements "
                . "de l'organisation (ils appartiennent à l'organisation, pas à un membre). `data` est `null`.",
            parameters: [$this->organizerIdParam(), $this->userIdParam()],
            security: $this->jwt(),
        );
    }

    private function transfererResponsabilite(): Operation
    {
        return new Operation(
            operationId: 'api_organizer_transfer_ownership',
            tags: [self::TAG_MEMBRES],
            responses: [
                '200' => new Response('Responsable changé.', $this->contenu($this->enveloppe([
                    'type' => 'object',
                    'properties' => [
                        'owner' => ['$ref' => '#/components/schemas/Membre'],
                        'members' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/Membre'],
                        ],
                    ],
                ]))),
                '400' => $this->erreurMetier('`demoteTo` inconnu.'),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '403' => $this->accesRefuse(),
                '404' => $this->erreurMetier('Organisation ou utilisateur introuvable.'),
            ],
            summary: 'Désigner un nouveau responsable',
            description: "Droit requis : **TRANSFER_OWNERSHIP** (responsable de l'organisation, ou "
                . "`ROLE_SUPER_ADMIN`).\n\n"
                . "La cible se désigne par `userId` ou `email` ; elle est rattachée à l'organisation si elle ne "
                . "l'était pas encore. `demoteTo` décide du sort des anciens responsables : `admin` par défaut "
                . "(ils gardent la main sur l'organisation qu'ils ont dirigée), une autre valeur de rôle, ou "
                . "**`null` explicite** pour les conserver co-responsables.\n\n"
                . "La promotion est appliquée **avant** les rétrogradations : l'organisation n'est à aucun "
                . "moment sans responsable.",
            parameters: [$this->organizerIdParam()],
            requestBody: new RequestBody('Nouveau responsable et sort des anciens', $this->contenu(
                [
                    'type' => 'object',
                    'properties' => [
                        'userId' => ['type' => 'integer', 'description' => 'Alternative à `email`.'],
                        'email' => [
                            'type' => 'string',
                            'format' => 'email',
                            'description' => 'Alternative à `userId`.',
                        ],
                        'demoteTo' => [
                            'type' => 'string',
                            'enum' => ['owner', 'admin', 'member', null],
                            'default' => 'admin',
                            'nullable' => true,
                            'description' => 'Rôle donné aux anciens responsables. '
                                . '`null` les conserve co-responsables.',
                        ],
                    ],
                ],
                ['userId' => 4, 'demoteTo' => 'admin']
            ), true),
            security: $this->jwt(),
        );
    }

    // ------------------------------------------------- Schémas partagés

    /**
     * Les formes qui reviennent dans plusieurs réponses sont déclarées une fois
     * dans `components.schemas` : la documentation reste lisible et un
     * changement de champ ne se recopie pas à six endroits.
     */
    private function avecSchemas(OpenApi $openApi): OpenApi
    {
        $role = $this->roleSchema();
        $roleLabel = [
            'type' => 'string',
            'enum' => ['Responsable', 'Administrateur', 'Membre'],
            'description' => 'Libellé affichable du rôle.',
        ];
        $joinedAt = [
            'type' => 'string',
            'format' => 'date-time',
            'description' => 'Date de rattachement à l’organisation.',
        ];

        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();

        $schemas['ErreurMetier'] = [
            'type' => 'object',
            'description' => 'Erreur métier rendue dans l’enveloppe maison par `BusinessExceptionSubscriber`.',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Message en français, affichable tel quel.'],
                'status' => ['type' => 'integer', 'description' => 'Répète le code HTTP de la réponse.'],
                'data' => ['nullable' => true, 'default' => null],
            ],
        ];

        $schemas['CompteUtilisateur'] = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'firstname' => ['type' => 'string'],
                'lastname' => ['type' => 'string'],
                'phone' => ['type' => 'string', 'nullable' => true],
                'language' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        $schemas['ProfilUtilisateur'] = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'firstname' => ['type' => 'string'],
                'lastname' => ['type' => 'string'],
                'phone' => ['type' => 'string', 'nullable' => true],
                'language' => ['type' => 'string', 'nullable' => true],
                'roles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Rôles **globaux** (`ROLE_USER`, `ROLE_SUPER_ADMIN`). '
                        . 'Les rôles dans les organisations sont ailleurs : `/api/user/me/organizations`.',
                ],
                'createdAt' => ['type' => 'string', 'example' => '2026-01-08 16:42:08'],
                'updatedAt' => ['type' => 'string', 'example' => '2026-01-08 16:42:08'],
            ],
        ];

        $schemas['Membre'] = [
            'type' => 'object',
            'description' => 'Un utilisateur vu depuis une organisation, avec son rôle.',
            'properties' => [
                'userId' => ['type' => 'integer', 'nullable' => true],
                'email' => ['type' => 'string', 'format' => 'email', 'nullable' => true],
                'firstname' => ['type' => 'string', 'nullable' => true],
                'lastname' => ['type' => 'string', 'nullable' => true],
                'phone' => ['type' => 'string', 'nullable' => true],
                'role' => $role,
                'roleLabel' => $roleLabel,
                'isOwner' => ['type' => 'boolean'],
                'joinedAt' => $joinedAt + ['nullable' => true],
            ],
        ];

        $schemas['OrganisationUtilisateur'] = [
            'type' => 'object',
            'description' => 'Une organisation vue depuis un utilisateur, avec le rôle qu’il y tient.',
            'properties' => [
                'id' => ['type' => 'integer', 'nullable' => true],
                'name' => ['type' => 'string', 'nullable' => true],
                'email' => ['type' => 'string', 'format' => 'email', 'nullable' => true],
                'phone' => ['type' => 'string', 'nullable' => true],
                'website' => ['type' => 'string', 'nullable' => true],
                'role' => $role,
                'roleLabel' => $roleLabel,
                'isOwner' => ['type' => 'boolean'],
                'joinedAt' => $joinedAt + ['nullable' => true],
            ],
        ];

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }

    private function roleSchema(?string $defaut = null): array
    {
        $schema = [
            'type' => 'string',
            'enum' => ['owner', 'admin', 'member'],
            'description' => 'Rôle dans l’organisation. Hiérarchique : `owner` > `admin` > `member`. '
                . 'La saisie est tolérante (`OWNER`, ` owner ` sont acceptés).',
        ];

        return null === $defaut ? $schema : $schema + ['default' => $defaut];
    }

    /** Enveloppe `{message, status, data}` commune aux contrôleurs maison. */
    private function enveloppe(array $dataSchema, int $status = 200): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'status' => ['type' => 'integer', 'example' => $status],
                'data' => $dataSchema,
            ],
        ];
    }

    private function contenu(array $schema, ?array $exemple = null): \ArrayObject
    {
        $mediaType = ['schema' => $schema];

        if (null !== $exemple) {
            $mediaType['example'] = $exemple;
        }

        return new \ArrayObject(['application/json' => $mediaType]);
    }

    private function erreurMetier(string $description): Response
    {
        return new Response(
            $description,
            $this->contenu(['$ref' => '#/components/schemas/ErreurMetier'])
        );
    }

    /**
     * Le `403` vient de `denyAccessUnlessGranted()`, pas d'une
     * `BusinessException` : il n'est donc **pas** enveloppé, contrairement aux
     * autres erreurs de ces endpoints.
     */
    private function accesRefuse(): Response
    {
        return new Response(
            'Droits insuffisants sur cette organisation. Réponse de Symfony, '
            . 'hors enveloppe `{message, status, data}`.'
        );
    }

    private function organizerIdParam(): Parameter
    {
        return new Parameter(
            name: 'organizerId',
            in: 'path',
            description: 'Identifiant de l’organisation.',
            required: true,
            schema: ['type' => 'integer'],
        );
    }

    private function userIdParam(): Parameter
    {
        return new Parameter(
            name: 'userId',
            in: 'path',
            description: 'Identifiant de l’utilisateur concerné.',
            required: true,
            schema: ['type' => 'integer'],
        );
    }

    /** @return array<int, array<string, array<never, never>>> */
    private function jwt(): array
    {
        return [['JWT' => []]];
    }
}
