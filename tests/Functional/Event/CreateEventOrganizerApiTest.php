<?php

namespace App\Tests\Functional\Event;

use App\Tests\Support\ApiTestCase;

/** POST /api/events ne crée plus d'organisation à la volée : elle doit exister. */
final class CreateEventOrganizerApiTest extends ApiTestCase
{
    private function body(array $organizer): array
    {
        return [
            'title' => 'Concert',
            'description' => 'Test',
            'startedAt' => '2027-01-01T18:00:00+03:00',
            'endAt' => '2027-01-01T22:00:00+03:00',
            'category' => ['name' => 'Musique', 'color' => '#fff'],
            'location' => ['name' => 'Salle', 'size' => 100],
            'organizer' => $organizer,
        ];
    }

    public function testRefusesInlineOrganizerCreation(): void
    {
        $user = $this->data->user();

        $response = $this->request('POST', '/api/events', $this->body(['name' => 'Fantôme', 'email' => 'x@y.mg']), $user);

        self::assertStatus(400, $response);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organizer'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event'));
    }
}
