<?php

namespace App\Tests\Integration\Auth;

use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;

/**
 * `refresh_tokens` n'a pas de clé étrangère vers `user` (lien par email, imposé
 * par le bundle Gesdinet). L'application doit donc maintenir elle-même la
 * cohérence : pas de jeton orphelin quand un compte est supprimé ou change
 * d'email (open-questions.md Q-22).
 */
final class RefreshTokenCleanupTest extends DatabaseTestCase
{
    public function testSupprimerUnCompteSupprimeSesRefreshTokens(): void
    {
        $user = $this->data->user('parti@test.mg');
        $other = $this->data->user('reste@test.mg');
        $this->insertToken('parti@test.mg');
        $this->insertToken('parti@test.mg');
        $this->insertToken('reste@test.mg');

        $this->em()->remove($user);
        $this->em()->flush();

        self::assertSame(0, $this->tokensFor('parti@test.mg'));
        self::assertSame(1, $this->tokensFor('reste@test.mg'));
    }

    public function testChangerDEmailConserveLesSessionsSousLeNouvelEmail(): void
    {
        $user = $this->data->user('ancien@test.mg');
        $this->insertToken('ancien@test.mg');

        $user->setEmail('nouveau@test.mg');
        $this->em()->flush();

        self::assertSame(0, $this->tokensFor('ancien@test.mg'), 'Aucun jeton orphelin sous l’ancien email.');
        self::assertSame(1, $this->tokensFor('nouveau@test.mg'));
    }

    public function testUneModificationSansChangementDEmailNeTouchePasAuxJetons(): void
    {
        $user = $this->data->user('stable@test.mg');
        $this->insertToken('stable@test.mg');

        $user->setFirstname('Autre');
        $this->em()->flush();

        self::assertSame(1, $this->tokensFor('stable@test.mg'));
    }

    public function testLesColonnesDeRechercheSontIndexees(): void
    {
        $indexes = $this->db()->fetchFirstColumn(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'refresh_tokens'"
        );

        self::assertNotEmpty(preg_grep('/\(username\)/', $indexes), 'Index sur username (jetons d’un utilisateur).');
        self::assertNotEmpty(preg_grep('/\(valid\)/', $indexes), 'Index sur valid (purge des jetons expirés).');
    }

    private function insertToken(string $email): void
    {
        $this->db()->executeStatement(
            "INSERT INTO refresh_tokens (refresh_token, username, valid) VALUES (?, ?, NOW() + INTERVAL '30 days')",
            [bin2hex(random_bytes(64)), $email]
        );
    }

    private function tokensFor(string $email): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]);
    }
}
