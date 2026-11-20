<?php

namespace App\Tests\Functional\Auth;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle de vie des refresh tokens par l'API : connexion, rafraîchissement,
 * déconnexion d'un appareil, déconnexion de tous les appareils.
 */
final class RefreshTokenApiTest extends ApiTestCase
{
    private const PASSWORD = 'MotDePasse-Test-2026!';

    // ------------------------------------------------------------- connexion

    public function testLaConnexionCreeUnSeulRefreshToken(): void
    {
        $user = $this->userWithPassword();

        $body = $this->login($user);

        self::assertNotEmpty($body['token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame(1, $this->tokensOf($user), 'Un seul jeton par connexion, sans doublon orphelin.');
        self::assertSame(
            1,
            $this->fetchInt('SELECT COUNT(*) FROM refresh_tokens WHERE refresh_token = ?', [$body['refresh_token']]),
            'Le jeton renvoyé est celui enregistré en base.'
        );
    }

    public function testLeRafraichissementRemplaceLeJeton(): void
    {
        $user = $this->userWithPassword();
        $old = $this->login($user)['refresh_token'];

        $response = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $old]);
        self::assertStatus(200, $response);
        $new = $this->json($response)['refresh_token'];

        self::assertNotSame($old, $new);
        self::assertSame(1, $this->tokensOf($user), 'Usage unique : l’ancien jeton est supprimé.');
        self::assertStatus(401, $this->request('POST', '/api/auth/refresh', ['refresh_token' => $old]));
    }

    // ----------------------------------------------------------- déconnexion

    public function testLaDeconnexionInvalideLeRefreshToken(): void
    {
        $user = $this->userWithPassword();
        $token = $this->login($user)['refresh_token'];
        $other = $this->login($user)['refresh_token']; // deuxième appareil

        $body = $this->assertEnvelope($this->request('POST', '/api/auth/logout', ['refresh_token' => $token]), 200);

        self::assertNull($body['data']);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM refresh_tokens WHERE refresh_token = ?', [$token]));
        self::assertSame(1, $this->tokensOf($user), 'Les autres appareils restent connectés.');
        self::assertStatus(401, $this->request('POST', '/api/auth/refresh', ['refresh_token' => $token]));
        self::assertStatus(200, $this->request('POST', '/api/auth/refresh', ['refresh_token' => $other]));
    }

    public function testLaDeconnexionFonctionneSansAccessTokenValide(): void
    {
        // L'access token a pu expirer : le refresh token suffit à se déconnecter.
        $token = $this->login($this->userWithPassword())['refresh_token'];

        $this->assertEnvelope($this->request('POST', '/api/auth/logout', ['refresh_token' => $token]), 200);
    }

    public function testUnJetonInconnuOuDejaInvalideRepond200(): void
    {
        // Idempotent, et ne révèle pas si le jeton a existé.
        $this->assertEnvelope($this->request('POST', '/api/auth/logout', ['refresh_token' => str_repeat('a', 128)]), 200);
    }

    #[DataProvider('corpsInvalides')]
    public function testUnCorpsSansJetonEstRefuse(array|string|null $body): void
    {
        $this->assertEnvelope($this->request('POST', '/api/auth/logout', $body), 400);
    }

    public static function corpsInvalides(): iterable
    {
        yield 'sans corps' => [null];
        yield 'JSON invalide' => ['{pas du json'];
        yield 'champ absent' => [[]];
        yield 'champ vide' => [['refresh_token' => '']];
        yield 'mauvais type' => [['refresh_token' => 123]];
    }

    // ------------------------------------------- déconnexion de tous les appareils

    public function testLaDeconnexionGlobaleSupprimeTousLesJetonsDeLUtilisateur(): void
    {
        $user = $this->userWithPassword();
        $someoneElse = $this->userWithPassword('autre@test.mg');
        $this->login($user);
        $this->login($user);
        $this->login($someoneElse);

        $body = $this->assertEnvelope($this->request('POST', '/api/auth/logout-all', null, $user), 200);

        self::assertSame(['revoked' => 2], $body['data']);
        self::assertSame(0, $this->tokensOf($user));
        self::assertSame(1, $this->tokensOf($someoneElse), 'Les sessions des autres comptes ne sont pas touchées.');
    }

    public function testLaDeconnexionGlobaleExigeUnAccessToken(): void
    {
        $this->login($this->userWithPassword());

        self::assertStatus(401, $this->request('POST', '/api/auth/logout-all'));
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM refresh_tokens'));
    }

    // ---------------------------------------------------------------- outils

    private function userWithPassword(string $email = 'connexion@test.mg'): User
    {
        $user = $this->data->user($email);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->flush();

        return $user;
    }

    private function login(User $user): array
    {
        $response = $this->request('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);
        self::assertStatus(200, $response);

        return $this->json($response);
    }

    private function tokensOf(User $user): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$user->getEmail()]);
    }
}
