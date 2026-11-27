<?php

namespace App\Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;

/**
 * En test, le stockage est en mémoire (config/packages/flysystem.yaml) et ne
 * sait pas présigner : on génère une URL prévisible à la place.
 */
final class FakeTemporaryUrlGenerator implements TemporaryUrlGenerator
{
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        return sprintf('https://storage.test/%s?expires=%d', $path, $expiresAt->getTimestamp());
    }
}
