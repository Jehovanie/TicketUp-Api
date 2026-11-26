<?php

namespace App\Service\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Accès au bucket des fichiers utilisateurs (MinIO / S3).
 *
 * Le bucket est privé : on stocke en base la **clé** de l'objet
 * (ex. `events/42/0193…-affiche.jpg`), jamais une URL. Pour afficher un
 * fichier, le client reçoit une URL présignée à durée limitée
 * ({@see self::temporaryUrl()}).
 */
final class FileStorage
{
    public function __construct(
        private readonly FilesystemOperator $userFilesStorage,
        private readonly int $presignedUrlTtl = 900,
    ) {}

    /**
     * Envoie un fichier uploadé et retourne sa clé dans le bucket.
     *
     * @param string $directory préfixe logique, ex. `events/42` ou `organizers/7/logo`
     *
     * @throws FilesystemException
     */
    public function upload(UploadedFile $file, string $directory): string
    {
        $key = $this->buildKey($directory, $file->getClientOriginalName());

        $stream = fopen($file->getPathname(), 'rb');
        try {
            $this->userFilesStorage->writeStream($key, $stream, [
                'ContentType' => self::detectMimeType($file->getPathname()),
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $key;
    }

    /** @throws FilesystemException */
    public function write(string $key, string $contents, ?string $contentType = null): void
    {
        $this->userFilesStorage->write($key, $contents, $contentType ? ['ContentType' => $contentType] : []);
    }

    /** @throws FilesystemException */
    public function read(string $key): string
    {
        return $this->userFilesStorage->read($key);
    }

    /** @throws FilesystemException */
    public function exists(string $key): bool
    {
        return $this->userFilesStorage->fileExists($key);
    }

    /** Suppression idempotente : ne lève pas d'erreur si l'objet n'existe pas. */
    public function delete(string $key): void
    {
        $this->userFilesStorage->delete($key);
    }

    /**
     * URL présignée (GET) valable `$ttl` secondes (défaut : S3_PRESIGNED_URL_TTL).
     *
     * @throws FilesystemException
     */
    public function temporaryUrl(string $key, ?int $ttl = null): string
    {
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $ttl ?? $this->presignedUrlTtl));

        return $this->userFilesStorage->temporaryUrl($key, $expiresAt);
    }

    /**
     * Type MIME détecté sur le contenu (ext-fileinfo), pas celui envoyé par le client.
     * Utile aussi pour valider un upload avant de l'envoyer.
     */
    public static function detectMimeType(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && '' !== $mime ? $mime : 'application/octet-stream';
    }

    /** Clé unique et lisible : `<dossier>/<uuid v7>-<nom-slugifié>.<ext>` */
    private function buildKey(string $directory, string $originalName): string
    {
        $name = (new AsciiSlugger())->slug(pathinfo($originalName, PATHINFO_FILENAME))->lower()->truncate(60)->toString();
        $extension = (new AsciiSlugger())->slug(pathinfo($originalName, PATHINFO_EXTENSION))->lower()->truncate(10)->toString();

        return sprintf(
            '%s/%s%s%s',
            trim($directory, '/'),
            Uuid::v7()->toRfc4122(),
            '' !== $name ? '-'.$name : '',
            '' !== $extension ? '.'.$extension : '',
        );
    }
}
