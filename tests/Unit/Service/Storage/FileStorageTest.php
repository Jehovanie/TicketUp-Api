<?php

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\FileStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileStorageTest extends TestCase
{
    private Filesystem $filesystem;
    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->storage = new FileStorage($this->filesystem);
    }

    public function testUploadStoresFileUnderDirectoryWithUniqueSluggedKey(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'hello');
        $file = new UploadedFile($tmp, 'Affiche Été 2026.txt', 'text/plain', null, true);

        $key = $this->storage->upload($file, '/events/42/');

        self::assertMatchesRegularExpression('#^events/42/[0-9a-f-]{36}-affiche-ete-2026\.txt$#', $key);
        self::assertTrue($this->storage->exists($key));
        self::assertSame('hello', $this->storage->read($key));

        self::assertNotSame($key, $this->storage->upload($file, 'events/42'), 'deux uploads du même nom ne doivent pas s\'écraser');
        unlink($tmp);
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->storage->write('a/b.txt', 'x');
        $this->storage->delete('a/b.txt');
        $this->storage->delete('a/b.txt');

        self::assertFalse($this->storage->exists('a/b.txt'));
    }
}
