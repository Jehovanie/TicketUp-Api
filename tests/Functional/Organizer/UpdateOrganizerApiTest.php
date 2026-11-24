<?php

namespace App\Tests\Functional\Organizer;

use App\Entity\Organizer;
use App\Entity\OrganizerPaymentMethod;
use App\Entity\User;
use App\Enum\MobileMoneyProvider;
use App\Enum\OrganizerRole;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PATCH /api/organizers/{id}, GET /api/organizers/{id} (fiche membre).
 */
final class UpdateOrganizerApiTest extends ApiTestCase
{
    private User $owner;
    private User $admin;
    private User $member;
    private User $stranger;
    private Organizer $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->data->user();
        $this->admin = $this->data->user();
        $this->member = $this->data->user();
        $this->stranger = $this->data->user();
        $this->organizer = $this->data->organizer('Tech Madagascar', $this->owner);
        $this->data->membership($this->admin, $this->organizer, OrganizerRole::ADMIN);
        $this->data->membership($this->member, $this->organizer, OrganizerRole::MEMBER);

        $this->organizer
            ->setPhone('0341234567')
            ->setWebsite('https://techmada.mg')
            ->addPaymentMethod(
                (new OrganizerPaymentMethod())
                    ->setProvider(MobileMoneyProvider::MVOLA)
                    ->setPhoneNumber('+261341234567')
                    ->setAccountName('RAKOTO Jean')
            );
        $this->data->save($this->organizer);
    }

    private function url(): string
    {
        return '/api/organizers/' . $this->organizer->getId();
    }

    private function row(): array
    {
        return $this->db()->fetchAssociative('SELECT * FROM organizer WHERE id = ?', [$this->organizer->getId()]);
    }

    private function methods(): array
    {
        return $this->db()->fetchAllKeyValue(
            'SELECT provider, phone_number FROM organizer_payment_method WHERE organizer_id = ? ORDER BY provider',
            [$this->organizer->getId()]
        );
    }

    // ------------------------------------------------------------- Droits

    public function testRequiresAuthentication(): void
    {
        self::assertStatus(401, $this->request('PATCH', $this->url(), ['name' => 'X']));
    }

    #[DataProvider('forbiddenCallers')]
    public function testRefusesBelowAdmin(string $caller): void
    {
        $response = $this->request('PATCH', $this->url(), ['name' => 'Piraté'], $this->$caller);

        self::assertStatus(403, $response);
        self::assertSame('Tech Madagascar', $this->row()['name']);
    }

    public static function forbiddenCallers(): iterable
    {
        yield 'membre simple' => ['member'];
        yield 'étranger' => ['stranger'];
    }

    public function testAdminAndSuperAdminCanEdit(): void
    {
        self::assertStatus(200, $this->request('PATCH', $this->url(), ['name' => 'Par admin'], $this->admin));

        $root = $this->data->user(roles: [User::ROLE_SUPER_ADMIN]);
        self::assertStatus(200, $this->request('PATCH', $this->url(), ['name' => 'Par root'], $root));
        self::assertSame('Par root', $this->row()['name']);
    }

    public function testUnknownOrganizerIs404(): void
    {
        self::assertStatus(404, $this->request('PATCH', '/api/organizers/999999', ['name' => 'X'], $this->owner));
    }

    // ------------------------------------------------------------- Champs

    public function testPartialUpdateOnlyTouchesGivenFields(): void
    {
        $response = $this->request('PATCH', $this->url(), ['name' => '  Nouveau nom  ', 'email' => 'NOUVEAU@Techmada.MG'], $this->owner);

        self::assertStatus(200, $response);
        $row = $this->row();
        self::assertSame('Nouveau nom', $row['name']);
        self::assertSame('nouveau@techmada.mg', $row['email']);
        self::assertSame('0341234567', $row['phone'], 'Champ absent : inchangé.');
        self::assertSame('https://techmada.mg', $row['website']);
        self::assertSame(['mvola' => '+261341234567'], $this->methods(), 'Comptes absents du corps : inchangés.');

        $data = $this->json($response)['data'];
        self::assertSame('Nouveau nom', $data['name']);
        self::assertSame('owner', $data['role']);
        self::assertTrue($data['isOwner']);
    }

    public function testPhoneAndWebsiteCanBeCleared(): void
    {
        self::assertStatus(200, $this->request('PATCH', $this->url(), ['phone' => null, 'website' => ''], $this->owner));

        $row = $this->row();
        self::assertNull($row['phone']);
        self::assertNull($row['website']);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidFieldsWithoutWriting(array $body, string $messagePart): void
    {
        $response = $this->request('PATCH', $this->url(), $body, $this->owner);

        self::assertStatus(400, $response);
        self::assertStringContainsString($messagePart, $this->json($response)['message']);
        self::assertSame('Tech Madagascar', $this->row()['name']);
    }

    public static function invalidFields(): iterable
    {
        yield 'corps vide' => [[], 'Aucun champ à modifier'];
        yield 'champ inconnu seul' => [['foo' => 'bar'], 'Aucun champ à modifier'];
        yield 'nom vidé' => [['name' => ' '], 'name'];
        yield 'email invalide' => [['email' => 'pas-un-email'], 'email'];
        yield 'site invalide' => [['website' => 'techmada'], 'adresse web'];
        yield 'nom trop long' => [['name' => str_repeat('a', 101)], '100'];
        yield 'valide + invalide : rien n’est écrit' => [['name' => 'Valide', 'email' => 'x'], 'email'];
    }

    // ----------------------------------------------------- Comptes mobile money

    public function testReplacesPaymentMethodsByProvider(): void
    {
        $id = (int) $this->db()->fetchOne('SELECT id FROM organizer_payment_method WHERE organizer_id = ?', [$this->organizer->getId()]);

        $response = $this->request('PATCH', $this->url(), ['paymentMethods' => [
            ['provider' => 'mvola', 'phoneNumber' => '038 11 222 33', 'accountName' => 'NOUVEAU Titulaire'],
            ['provider' => 'airtel_money', 'phoneNumber' => '033 11 222 33', 'accountName' => 'RAKOTO Jean'],
        ]], $this->admin);

        self::assertStatus(200, $response);
        self::assertSame(['airtel_money' => '+261331122233', 'mvola' => '+261381122233'], $this->methods());
        self::assertSame(
            $id,
            (int) $this->db()->fetchOne("SELECT id FROM organizer_payment_method WHERE provider = 'mvola'"),
            'Le compte conservé est mis à jour sur place.'
        );
        self::assertSame(['mvola', 'airtel_money'], array_column($this->json($response)['data']['paymentMethods'], 'provider'));
    }

    public function testRemovesProviderAbsentFromNewList(): void
    {
        $this->request('PATCH', $this->url(), ['paymentMethods' => [
            ['provider' => 'orange_money', 'phoneNumber' => '032 11 222 33', 'accountName' => 'X'],
        ]], $this->owner);

        self::assertSame(['orange_money' => '+261321122233'], $this->methods());
    }

    #[DataProvider('invalidPaymentMethods')]
    public function testPaymentMethodRulesAreTheSameAsOnCreation(mixed $paymentMethods, string $messagePart): void
    {
        $response = $this->request('PATCH', $this->url(), ['name' => 'Changé', 'paymentMethods' => $paymentMethods], $this->owner);

        self::assertStatus(400, $response);
        self::assertStringContainsString($messagePart, $this->json($response)['message']);
        self::assertSame(['mvola' => '+261341234567'], $this->methods());
        self::assertSame('Tech Madagascar', $this->row()['name'], 'Rien n’est écrit si une partie est invalide.');
    }

    public static function invalidPaymentMethods(): iterable
    {
        $ok = ['provider' => 'mvola', 'phoneNumber' => '0341234567', 'accountName' => 'RAKOTO'];

        yield 'liste vide : on ne retire pas le dernier compte' => [[], 'Au moins un compte'];
        yield 'null' => [null, 'Au moins un compte'];
        yield 'doublon d’opérateur' => [[$ok, ['phoneNumber' => '0381234567'] + $ok], 'Un seul compte MVola'];
        yield 'numéro d’un autre opérateur' => [[['phoneNumber' => '0331234567'] + $ok], 'n’est pas un numéro MVola'];
        yield 'opérateur inconnu' => [[['provider' => 'paypal'] + $ok], 'opérateur'];
        yield 'titulaire manquant' => [[['accountName' => ''] + $ok], 'accountName'];
    }

    // --------------------------------------------------------- Fiche membre

    public function testMemberSeesFullDetailWithRoleAndPaymentMethods(): void
    {
        $response = $this->request('GET', $this->url(), null, $this->member);

        self::assertStatus(200, $response);
        $data = $this->json($response)['data'];
        self::assertSame('Tech Madagascar', $data['name']);
        self::assertSame('member', $data['role']);
        self::assertFalse($data['isOwner']);
        self::assertSame('+261341234567', $data['paymentMethods'][0]['phoneNumber']);
        self::assertNotNull($data['createdAt']);
    }

    public function testDetailIsForbiddenToStrangersButOpenToSuperAdmin(): void
    {
        self::assertStatus(401, $this->request('GET', $this->url()));
        self::assertStatus(403, $this->request('GET', $this->url(), null, $this->stranger));

        $root = $this->data->user(roles: [User::ROLE_SUPER_ADMIN]);
        $data = $this->json($this->request('GET', $this->url(), null, $root))['data'];
        self::assertNull($data['role']);
        self::assertFalse($data['isOwner']);
        self::assertCount(1, $data['paymentMethods']);
    }

    public function testPublicResourceStillHidesPaymentMethods(): void
    {
        $response = $this->request('GET', '/api/organizer/' . $this->organizer->getId());

        self::assertStatus(200, $response);
        self::assertStringNotContainsString('paymentMethods', (string) $response->getContent());
        self::assertStringNotContainsString('+261341234567', (string) $response->getContent());
    }
}
