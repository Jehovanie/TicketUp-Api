<?php

namespace App\Tests\Functional\Event;

use App\Entity\Category;
use App\Entity\Location;
use App\Entity\Organizer;
use App\Entity\User;
use App\Service\Event\EventImages;
use App\Service\Storage\FileStorage;
use App\Tests\Support\ApiTestCase;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/events : création avec 0 à 3 images (multipart), membre de
 * l'organisation requis, tout ou rien.
 *
 * Le stockage est en mémoire en environnement de test (flysystem.yaml).
 */
final class CreateEventImagesApiTest extends ApiTestCase
{
    private User $member;
    private User $stranger;
    private Organizer $organizer;
    private Category $category;
    private Location $location;

    /** @var list<string> fichiers temporaires à supprimer */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->data->user();
        $this->stranger = $this->data->user();
        $this->organizer = $this->data->organizer('Tech', $this->member);
        $this->category = $this->data->category();
        $this->location = $this->data->location(300);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tmpFiles, 'is_file'));
        parent::tearDown();
    }

    private function body(): array
    {
        return [
            'title' => 'Festival',
            'description' => 'Trois jours de musique.',
            'startedAt' => '2027-03-01T18:00:00+03:00',
            'endAt' => '2027-03-01T23:00:00+03:00',
            'organizer' => ['id' => $this->organizer->getId()],
            'category' => ['id' => $this->category->getId()],
            'location' => ['id' => $this->location->getId()],
            'ticket_type' => [['name' => 'Standard', 'prix' => 10000, 'quantite_max' => 50]],
        ];
    }

    /** @param list<UploadedFile> $images */
    private function create(array $images, ?User $as, ?string $data = null): Response
    {
        return $this->multipart(
            'POST',
            '/api/events',
            ['data' => $data ?? json_encode($this->body())],
            [] === $images ? [] : ['images' => $images],
            $as,
        );
    }

    private function png(string $name = 'affiche.png', int $padTo = 0): UploadedFile
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $this->file($name, $bytes . str_repeat("\0", max(0, $padTo - strlen($bytes))), 'image/png');
    }

    private function file(string $name, string $contents, string $clientType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evtimg');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, $clientType, null, true);
    }

    private function storage(): FileStorage
    {
        return static::getContainer()->get(FileStorage::class);
    }

    private function eventCount(): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM event');
    }

    // ------------------------------------------------------------------ Succès

    public function testJsonWithoutImagesStillWorks(): void
    {
        $response = $this->request('POST', '/api/events', $this->body(), $this->member);

        $body = $this->assertEnvelope($response, 201);
        self::assertSame('Festival', $body['data']['title']);
        self::assertSame([], $body['data']['images']);
        self::assertSame('[]', $this->fetchValue('SELECT images::text FROM event'));
    }

    public function testMultipartWithoutImagesIsAccepted(): void
    {
        $body = $this->assertEnvelope($this->create([], $this->member), 201);

        self::assertSame([], $body['data']['images']);
        self::assertSame(1, $this->eventCount());
    }

    public function testThreeImagesAreStoredUnderTheEventId(): void
    {
        $response = $this->create([$this->png('a.png'), $this->png('b.png'), $this->png('c.png')], $this->member);

        $body = $this->assertEnvelope($response, 201);
        $id = $body['data']['id'];
        $images = $body['data']['images'];

        self::assertCount(3, $images);
        foreach (['a', 'b', 'c'] as $i => $name) {
            self::assertMatchesRegularExpression("#^events/$id/[0-9a-f-]{36}-$name\\.png$#", $images[$i]['key'], 'ordre d’envoi conservé');
            self::assertStringStartsWith('https://storage.test/' . $images[$i]['key'], $images[$i]['url']);
            self::assertTrue($this->storage()->exists($images[$i]['key']));
        }

        self::assertSame(
            array_column($images, 'key'),
            json_decode((string) $this->fetchValue('SELECT images FROM event WHERE id = ?', [$id]), true)
        );
    }

    public function testImagesAreExposedOnEventDetails(): void
    {
        $created = $this->assertEnvelope($this->create([$this->png()], $this->member), 201);

        $details = $this->assertEnvelope($this->request('GET', '/api/events/' . $created['data']['id']), 200);

        self::assertCount(1, $details['data']['images']);
        self::assertSame($created['data']['images'][0]['key'], $details['data']['images'][0]['key']);
    }

    // ----------------------------------------------------------------- Refus

    public function testFourImagesAreRefusedAndNothingIsCreated(): void
    {
        $response = $this->create([$this->png(), $this->png(), $this->png(), $this->png()], $this->member);

        $body = $this->assertEnvelope($response, 400);
        self::assertStringContainsString('au plus 3 images', $body['message']);
        self::assertSame(0, $this->eventCount());
    }

    public function testNonImageFileIsRefusedEvenWithImageExtension(): void
    {
        $fake = $this->file('virus.png', "<?php echo 'hello';", 'image/png');

        $body = $this->assertEnvelope($this->create([$this->png(), $fake], $this->member), 400);

        self::assertStringContainsString('virus.png', $body['message']);
        self::assertSame(0, $this->eventCount());
    }

    public function testTooLargeImageIsRefused(): void
    {
        $big = $this->png('enorme.png', EventImages::MAX_SIZE + 1);

        $body = $this->assertEnvelope($this->create([$big], $this->member), 400);

        self::assertStringContainsString('5 Mo', $body['message']);
        self::assertSame(0, $this->eventCount());
    }

    public function testInvalidDataFieldIsRefused(): void
    {
        $this->assertEnvelope($this->create([], $this->member, '{pas du json'), 400);
        self::assertSame(0, $this->eventCount());
    }

    // ----------------------------------------------------------------- Droits

    public function testAnonymousCannotCreate(): void
    {
        self::assertStatus(401, $this->create([$this->png()], null));
        self::assertSame(0, $this->eventCount());
    }

    public function testNonMemberCannotCreateNorUpload(): void
    {
        $body = $this->assertEnvelope($this->create([$this->png()], $this->stranger), 403);

        self::assertStringContainsString('membre', $body['message']);
        self::assertSame(0, $this->eventCount());
        self::assertSame([], $this->storage()->listKeys('events'));
    }

    // -------------------------------------------------------- Tout ou rien

    public function testStorageFailureRollsBackAndCleansUploadedImages(): void
    {
        // Le 1er envoi réussit, le 2e échoue : le 1er doit être supprimé.
        $adapter = new class extends InMemoryFilesystemAdapter {
            private int $writes = 0;

            public function writeStream(string $path, $contents, Config $config): void
            {
                if (++$this->writes > 1) {
                    throw UnableToWriteFile::atLocation($path, 'MinIO injoignable');
                }
                parent::writeStream($path, $contents, $config);
            }
        };
        static::getContainer()->set(FileStorage::class, $storage = new FileStorage(new Filesystem($adapter)));

        $response = $this->create([$this->png('a.png'), $this->png('b.png')], $this->member);

        $this->assertEnvelope($response, 503);
        self::assertSame(0, $this->eventCount());
        self::assertSame([], $storage->listKeys('events'));
    }
}
