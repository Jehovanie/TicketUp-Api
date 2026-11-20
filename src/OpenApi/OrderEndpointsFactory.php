<?php

namespace App\OpenApi;

use App\Enum\OrderStatus;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\OpenApi\OpenApi;
use App\Service\Order\OrderService;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documente dans `/api/docs` les endpoints de commande de billets
 * (`src/Controller/Api/Order/`), contrôleurs Symfony classiques invisibles pour
 * API Platform.
 *
 * Même principe que `UndocumentedEndpointsFactory` (description seulement,
 * aucun effet sur les routes), séparé pour garder chaque domaine lisible.
 * À mettre à jour avec les contrôleurs et `OrderPresenter`.
 */
#[AsDecorator('api_platform.openapi.factory')]
final class OrderEndpointsFactory implements OpenApiFactoryInterface
{
    private const TAG = 'Commandes';

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $openApi = $this->withSchemas($openApi);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/orders', new PathItem(post: $this->place()));
        $paths->addPath('/api/orders/me', new PathItem(get: $this->mine()));
        $paths->addPath('/api/orders/{reference}', new PathItem(get: $this->show()));
        $paths->addPath('/api/orders/{reference}/cancel', new PathItem(post: $this->cancel()));
        $paths->addPath('/api/events/{eventId}/orders', new PathItem(get: $this->eventOrders()));

        return $openApi->withTags(array_merge($openApi->getTags(), [
            new Tag(
                self::TAG,
                'Commande de billets. Une commande payante réserve ses places pendant '
                . OrderService::RESERVATION_MINUTES . ' minutes, le temps du paiement ; une commande gratuite '
                . 'est payée immédiatement et ses billets sont émis. Une commande se désigne par sa '
                . '`reference` (`TU-XXXXXXXX`), jamais par un identifiant numérique.'
            ),
        ]));
    }

    private function place(): Operation
    {
        return new Operation(
            operationId: 'api_order_place',
            tags: [self::TAG],
            responses: [
                '201' => new Response('Commande créée.', $this->content($this->envelope(['$ref' => '#/components/schemas/Commande'], 201))),
                '200' => new Response(
                    'Renvoi avec une `Idempotency-Key` déjà utilisée : la commande existante est renvoyée, rien n’est réservé de plus.',
                    $this->content($this->envelope(['$ref' => '#/components/schemas/Commande']))
                ),
                '400' => $this->error('Corps invalide : JSON mal formé, champ manquant ou mal typé, aucune ligne, quantité ≤ 0, tarif en double ou étranger à l’événement, plus de ' . OrderService::MAX_TICKETS_PER_ORDER . ' billets, clé d’idempotence de plus de 64 caractères.'),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '404' => $this->error('Événement inexistant ou non publié.'),
                '409' => $this->error('Événement terminé, ou plus assez de places pour un tarif (le message le nomme). Rien n’est réservé.'),
            ],
            summary: 'Commander des billets',
            description: "Réserve les places et enregistre la commande. Le total est calculé à partir des tarifs en base : "
                . "un prix envoyé par le client est ignoré.\n\n"
                . "- Total > 0 : statut `pending`, places bloquées jusqu'à `expiresAt`. Passé ce délai sans paiement, "
                . "la commande expire et les places sont remises en vente.\n"
                . "- Total = 0 : statut `paid`, billets émis dans `tickets`.\n\n"
                . "En-tête facultatif **`Idempotency-Key`** (≤ 64 caractères) : à générer côté client par commande ; "
                . "en cas de renvoi réseau, la même commande est renvoyée en `200` au lieu d'en créer une seconde.",
            parameters: [
                new Parameter(
                    name: 'Idempotency-Key',
                    in: 'header',
                    description: 'Identifiant unique de la tentative de commande, généré par le client (UUID conseillé).',
                    required: false,
                    schema: ['type' => 'string', 'maxLength' => 64],
                ),
            ],
            requestBody: new RequestBody('Événement et quantités par tarif', $this->content(
                [
                    'type' => 'object',
                    'required' => ['eventId', 'items'],
                    'properties' => [
                        'eventId' => ['type' => 'integer'],
                        'items' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['ticketTypeId', 'quantity'],
                                'properties' => [
                                    'ticketTypeId' => ['type' => 'integer'],
                                    'quantity' => ['type' => 'integer', 'minimum' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                ['eventId' => 12, 'items' => [['ticketTypeId' => 31, 'quantity' => 2], ['ticketTypeId' => 32, 'quantity' => 1]]]
            ), true),
            security: $this->jwt(),
        );
    }

    private function mine(): Operation
    {
        return new Operation(
            operationId: 'api_order_mine',
            tags: [self::TAG],
            responses: [
                '200' => new Response('Commandes de l’utilisateur, de la plus récente à la plus ancienne.', $this->content($this->envelope($this->collection()))),
                '400' => $this->error('Valeur de `status` inconnue.'),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
            ],
            summary: 'Mes commandes',
            description: 'Filtrable par statut (`?status=pending`) pour les onglets du front. '
                . '`countsByStatus` donne le nombre de commandes par statut, sans tenir compte du filtre.',
            parameters: [$this->statusParameter(), ...$this->paginationParameters()],
            security: $this->jwt(),
        );
    }

    private function show(): Operation
    {
        return new Operation(
            operationId: 'api_order_show',
            tags: [self::TAG],
            responses: [
                '200' => new Response('Commande et billets. `buyer` n’est présent que pour un membre de l’organisation ou le super administrateur.', $this->content($this->envelope(['$ref' => '#/components/schemas/Commande']))),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '404' => $this->error('Référence inconnue, ou commande que l’appelant n’a pas le droit de voir.'),
            ],
            summary: 'Détail d’une commande',
            description: 'Visible par l’acheteur, par tout membre de l’organisation de l’événement et par le super administrateur. '
                . 'Pour les autres, la réponse est `404` : on ne révèle pas qu’une référence existe.',
            parameters: [$this->referenceParameter()],
            security: $this->jwt(),
        );
    }

    private function cancel(): Operation
    {
        return new Operation(
            operationId: 'api_order_cancel',
            tags: [self::TAG],
            responses: [
                '200' => new Response('Commande annulée, places remises en vente.', $this->content($this->envelope(['$ref' => '#/components/schemas/Commande']))),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '404' => $this->error('Référence inconnue, ou commande d’un autre utilisateur.'),
                '409' => $this->error('La commande n’est plus en attente (payée, expirée ou déjà annulée).'),
            ],
            summary: 'Annuler une commande en attente',
            description: 'Réservé à l’acheteur, tant que la commande est `pending`. Une commande payée relève du remboursement.',
            parameters: [$this->referenceParameter()],
            security: $this->jwt(),
        );
    }

    private function eventOrders(): Operation
    {
        return new Operation(
            operationId: 'api_event_orders',
            tags: [self::TAG],
            responses: [
                '200' => new Response('Commandes de l’événement, avec l’acheteur (`buyer`).', $this->content($this->envelope($this->collection(true)))),
                '401' => new Response('Jeton absent, invalide ou expiré.'),
                '400' => $this->error('Valeur de `status` inconnue.'),
                '403' => $this->error('L’appelant n’est pas membre de l’organisation qui porte l’événement.'),
                '404' => $this->error('Événement inexistant.'),
            ],
            summary: 'Commandes d’un événement (organisateur)',
            description: 'Réservé aux membres de l’organisation de l’événement (tout rôle) et au super administrateur. '
                . 'Filtrable par statut (`?status=paid`) ; `countsByStatus` ignore le filtre.',
            parameters: [
                new Parameter(name: 'eventId', in: 'path', description: 'Identifiant de l’événement.', required: true, schema: ['type' => 'integer']),
                $this->statusParameter(),
                ...$this->paginationParameters(),
            ],
            security: $this->jwt(),
        );
    }

    // ---------------------------------------------------------------- schémas

    private function withSchemas(OpenApi $openApi): OpenApi
    {
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();
        $date = ['type' => 'string', 'format' => 'date-time', 'nullable' => true];

        $schemas['Commande'] = [
            'type' => 'object',
            'properties' => [
                'reference' => ['type' => 'string', 'example' => 'TU-7K2M9QXA'],
                'status' => ['type' => 'string', 'enum' => $this->statuses()],
                'statusLabel' => ['type' => 'string', 'example' => 'En attente de paiement'],
                'totalAmount' => ['type' => 'integer', 'description' => 'Montant total en ariary.', 'example' => 90000],
                'currency' => ['type' => 'string', 'example' => 'MGA'],
                'ticketCount' => ['type' => 'integer'],
                'expiresAt' => $date + ['description' => 'Fin de la réservation des places (commande en attente).'],
                'paidAt' => $date,
                'cancelledAt' => $date,
                'createdAt' => $date,
                'event' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'startedAt' => $date,
                        'endAt' => $date,
                    ],
                ],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ticketTypeId' => ['type' => 'integer'],
                            'name' => ['type' => 'string', 'description' => 'Nom du tarif au moment de l’achat.'],
                            'unitPrice' => ['type' => 'integer', 'description' => 'Prix au moment de l’achat.'],
                            'quantity' => ['type' => 'integer'],
                            'lineTotal' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'tickets' => [
                    'type' => 'array',
                    'description' => 'Billets émis (vide tant que la commande n’est pas payée).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'description' => 'Contenu du QR code.'],
                            'status' => ['type' => 'string', 'enum' => ['valid', 'used', 'cancelled']],
                            'ticketTypeName' => ['type' => 'string'],
                            'holderName' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                ],
                'buyer' => [
                    'type' => 'object',
                    'description' => 'Vue organisateur uniquement.',
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'firstname' => ['type' => 'string'],
                        'lastname' => ['type' => 'string'],
                        'phone' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
            ],
        ];

        $schemas['ErreurMetier'] ??= [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'data' => ['nullable' => true, 'default' => null],
            ],
        ];

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }

    private function collection(bool $withEvent = false): array
    {
        $properties = [
            'itemsTotal' => ['type' => 'integer'],
            'currentPage' => ['type' => 'integer'],
            'nombreParPage' => ['type' => 'integer'],
            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Commande']],
            'countsByStatus' => [
                'type' => 'object',
                'description' => 'Nombre de commandes par statut (tous les statuts, 0 compris), indépendant du filtre `status`.',
                'properties' => array_fill_keys($this->statuses(), ['type' => 'integer']),
                'example' => ['pending' => 2, 'paid' => 5, 'cancelled' => 1, 'expired' => 3, 'refunded' => 0],
            ],
        ];

        if ($withEvent) {
            $properties = ['event' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']],
            ]] + $properties;
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /** @return list<string> valeurs de {@see OrderStatus}, source unique */
    private function statuses(): array
    {
        return array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases());
    }

    private function statusParameter(): Parameter
    {
        return new Parameter(
            name: 'status',
            in: 'query',
            description: 'Filtre par statut : une valeur ou plusieurs séparées par des virgules (`cancelled,expired`). '
                . 'Valeurs : `' . implode('`, `', $this->statuses()) . '`. Absent : tous les statuts.',
            required: false,
            schema: ['type' => 'string', 'example' => 'pending'],
        );
    }

    /** @return Parameter[] */
    private function paginationParameters(): array
    {
        return [
            new Parameter(name: 'page', in: 'query', description: 'Numéro de page (défaut : 1)', required: false, schema: ['type' => 'integer', 'default' => 1]),
            new Parameter(name: 'itemsPerPage', in: 'query', description: 'Éléments par page (défaut : 10, maximum : 20)', required: false, schema: ['type' => 'integer', 'default' => 10, 'maximum' => 20]),
        ];
    }

    private function referenceParameter(): Parameter
    {
        return new Parameter(name: 'reference', in: 'path', description: 'Référence de la commande.', required: true, schema: ['type' => 'string', 'example' => 'TU-7K2M9QXA']);
    }

    private function envelope(array $dataSchema, int $status = 200): array
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

    private function content(array $schema, ?array $example = null): \ArrayObject
    {
        $mediaType = ['schema' => $schema];
        if ($example !== null) {
            $mediaType['example'] = $example;
        }

        return new \ArrayObject(['application/json' => $mediaType]);
    }

    private function error(string $description): Response
    {
        return new Response($description, $this->content(['$ref' => '#/components/schemas/ErreurMetier']));
    }

    /** @return array<int, array<string, array<never, never>>> */
    private function jwt(): array
    {
        return [['JWT' => []]];
    }
}
