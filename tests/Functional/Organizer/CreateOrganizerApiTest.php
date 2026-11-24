<?php

namespace App\Tests\Functional\Organizer;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/organizers : comptes mobile money obligatoires (MVola, Orange Money,
 * Airtel Money), un par opérateur, numéro cohérent avec l'opérateur.
 */
final class CreateOrganizerApiTest extends ApiTestCase
{
    private function body(array $paymentMethods): array
    {
        return [
            'name' => 'Zaikabe Production',
            'email' => 'prod@zaikabe.mg',
            'paymentMethods' => $paymentMethods,
        ];
    }

    private function mvola(): array
    {
        return ['provider' => 'mvola', 'phoneNumber' => '034 12 345 67', 'accountName' => 'RAKOTO Jean'];
    }

    public function testCreatesOrganizerWithNormalizedPaymentMethods(): void
    {
        $user = $this->data->user();

        $response = $this->request('POST', '/api/organizers', $this->body([
            $this->mvola(),
            ['provider' => 'Orange Money', 'phoneNumber' => '+261 32 98 765 43', 'accountName' => 'RAKOTO Jean'],
        ]), $user);

        self::assertStatus(201, $response);
        $methods = $this->json($response)['data']['paymentMethods'];
        self::assertSame(['mvola', 'orange_money'], array_column($methods, 'provider'));
        self::assertSame('+261341234567', $methods[0]['phoneNumber']);
        self::assertSame('+261329876543', $methods[1]['phoneNumber']);
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organizer_payment_method'));
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidPaymentMethodsWithoutWriting(array|null $paymentMethods, string $messagePart): void
    {
        $user = $this->data->user();
        $body = $this->body($paymentMethods ?? []);
        if ($paymentMethods === null) {
            unset($body['paymentMethods']);
        }

        $response = $this->request('POST', '/api/organizers', $body, $user);

        self::assertStatus(400, $response);
        self::assertStringContainsString($messagePart, $this->json($response)["message"]);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organizer'));
    }

    public static function invalidBodies(): iterable
    {
        $ok = ['provider' => 'mvola', 'phoneNumber' => '0341234567', 'accountName' => 'RAKOTO'];

        yield 'absent' => [null, 'Au moins un compte'];
        yield 'liste vide' => [[], 'Au moins un compte'];
        yield 'opérateur inconnu' => [[['provider' => 'paypal'] + $ok], 'opérateur'];
        yield 'doublon d’opérateur' => [[$ok, ['phoneNumber' => '0381234567'] + $ok], 'Un seul compte MVola'];
        yield 'numéro d’un autre opérateur' => [[['phoneNumber' => '0331234567'] + $ok], 'n’est pas un numéro MVola'];
        yield 'numéro mal formé' => [[['phoneNumber' => '034 12'] + $ok], 'numéro malgache'];
        yield 'titulaire manquant' => [[['accountName' => ' '] + $ok], 'accountName'];
        yield 'élément non objet' => [['mvola'], 'paymentMethods'];
    }

    public function testDatabaseRefusesTwoAccountsForSameProvider(): void
    {
        $organizer = $this->data->organizer();
        $insert = fn () => $this->db()->executeStatement(
            "INSERT INTO organizer_payment_method (organizer_id, provider, phone_number, account_name, created_at, updated_at)
             VALUES (?, 'mvola', '+261341234567', 'X', NOW(), NOW())",
            [$organizer->getId()]
        );
        $insert();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $insert();
    }
}
