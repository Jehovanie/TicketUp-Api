<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * Vérifie l'outillage de test lui-même (kernel, base de test, JWT) sur des
 * endpoints qui existent déjà. Si ces tests échouent, les autres n'ont pas de
 * sens : corriger l'environnement d'abord.
 */
final class SmokeTest extends ApiTestCase
{
    public function testHealthEndpointRepond(): void
    {
        $response = $this->request('GET', '/api/health');

        self::assertStatus(200, $response);
        self::assertSame('OK', $this->json($response)['status']);
    }

    public function testUnJetonJwtAuthentifieLUtilisateur(): void
    {
        $user = $this->data->user('smoke@test.mg');

        $response = $this->request('GET', '/api/user/me', as: $user);

        self::assertStatus(200, $response);
        self::assertSame('smoke@test.mg', $this->json($response)['email']);
    }

    public function testSansJetonLaRouteProtegeeRepond401(): void
    {
        self::assertStatus(401, $this->request('GET', '/api/user/me'));
    }

    public function testLaBaseEstVideeEntreDeuxTests(): void
    {
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM "user"'));
    }
}
