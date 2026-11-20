<?php

namespace App\Tests\Support;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base des tests HTTP.
 *
 * Les requêtes sont envoyées directement au kernel (pas de `KernelBrowser` :
 * `symfony/browser-kit` n'est pas installable sur Symfony 7.2 à cause d'un avis
 * de sécurité sur `symfony/dom-crawler`). L'authentification se fait comme en
 * vrai : un jeton JWT signé par Lexik dans l'en-tête `Authorization`.
 *
 * ⚠️ Avant chaque requête, les services sont réinitialisés (jeton de sécurité,
 * EntityManager vidé) : les entités créées par le test sont alors détachées.
 * Créer toutes les données **avant** la première requête, et relire depuis la
 * base (SQL ou repository) pour vérifier un résultat.
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    /**
     * @param array|string|null     $body    tableau encodé en JSON, ou chaîne envoyée telle quelle
     * @param array<string, string> $headers en-têtes HTTP supplémentaires, ex. ['Idempotency-Key' => 'abc']
     */
    protected function request(
        string $method,
        string $uri,
        array|string|null $body = null,
        ?User $as = null,
        array $headers = [],
    ): Response {
        $container = static::getContainer();

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $container->get(JWTTokenManagerInterface::class)->create($as);
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        // Chaque requête repart d'un état propre, comme sur un vrai serveur.
        $container->get('services_resetter')->reset();

        $request = Request::create($uri, $method, [], [], [], $server, is_array($body) ? json_encode($body) : $body);
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);

        return $response;
    }

    /** Corps JSON décodé, avec un message d'échec lisible si ce n'est pas du JSON. */
    protected function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        self::assertIsArray(
            $decoded,
            sprintf('Réponse non JSON (HTTP %d) : %s', $response->getStatusCode(), substr((string) $response->getContent(), 0, 500))
        );

        return $decoded;
    }

    protected static function assertStatus(int $expected, Response $response): void
    {
        self::assertSame(
            $expected,
            $response->getStatusCode(),
            sprintf('Code HTTP inattendu. Corps : %s', substr((string) $response->getContent(), 0, 800))
        );
    }

    /** Vérifie l'enveloppe commune `{ message, status, data }` du projet. */
    protected function assertEnvelope(Response $response, int $status): array
    {
        self::assertStatus($status, $response);
        $body = $this->json($response);

        self::assertArrayHasKey('message', $body);
        self::assertIsString($body['message']);
        self::assertSame($status, $body['status']);
        self::assertArrayHasKey('data', $body);

        return $body;
    }
}
