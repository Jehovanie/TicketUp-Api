<?php

namespace App\Service\Event;

use App\Entity\Event;
use App\Exception\EventException;
use App\Service\Storage\FileStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Images d'un événement : validation des fichiers reçus et envoi vers le
 * bucket S3 sous `events/{id}/`.
 *
 * Règles : 0 à {@see Event::MAX_IMAGES} images, JPEG / PNG / WebP, au plus
 * {@see self::MAX_SIZE} octets chacune. Le type est détecté sur le contenu,
 * pas sur l'extension ni sur le type annoncé par le client.
 */
final class EventImages
{
    public const MAX_SIZE = 5 * 1024 * 1024;

    /** type MIME détecté => libellé affiché dans les erreurs */
    public const ACCEPTED_TYPES = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
    ];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Normalise et valide les fichiers de la requête (`images[]` ou `images`).
     * Les champs fichier laissés vides sont ignorés : aucune image est valide.
     *
     * @return list<UploadedFile>
     *
     * @throws EventException
     */
    public function validate(mixed $files): array
    {
        $files = array_values(array_filter(
            is_array($files) ? $files : [$files],
            static fn ($file) => null !== $file,
        ));

        if (count($files) > Event::MAX_IMAGES) {
            throw EventException::tropDImages(count($files), Event::MAX_IMAGES);
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                throw EventException::corpsInvalide();
            }

            $name = $file->getClientOriginalName();

            if (!$file->isValid()) {
                if (\in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    throw EventException::imageTropLourde($name, min(self::MAX_SIZE, UploadedFile::getMaxFilesize()));
                }
                throw EventException::imageNonRecue($name, $file->getErrorMessage());
            }

            if ($file->getSize() > self::MAX_SIZE) {
                throw EventException::imageTropLourde($name, self::MAX_SIZE);
            }

            $type = FileStorage::detectMimeType($file->getPathname());
            if (!isset(self::ACCEPTED_TYPES[$type])) {
                throw EventException::imageTypeInvalide($name, $type, array_values(self::ACCEPTED_TYPES));
            }
        }

        return $files;
    }

    /**
     * Envoie les images (déjà validées) et retourne leurs clés, dans l'ordre reçu.
     * En cas d'échec, les images déjà envoyées sont supprimées : tout ou rien.
     *
     * @param list<UploadedFile> $files
     *
     * @return list<string>
     *
     * @throws EventException stockage injoignable (503)
     */
    public function store(Event $event, array $files): array
    {
        $keys = [];

        try {
            foreach ($files as $file) {
                $keys[] = $this->storage->upload($file, sprintf('events/%d', $event->getId()));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l’envoi d’une image d’événement vers le stockage S3.', [
                'event' => $event->getId(),
                'exception' => $e,
            ]);
            $this->delete($keys);

            throw EventException::stockageIndisponible();
        }

        return $keys;
    }

    /**
     * Suppression « au mieux » : une image orpheline dans le bucket est
     * préférable à une erreur qui masquerait la cause d'origine.
     *
     * @param list<string> $keys
     */
    public function delete(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->storage->delete($key);
            } catch (\Throwable $e) {
                $this->logger->warning('Image d’événement orpheline dans le stockage S3.', ['key' => $key, 'exception' => $e]);
            }
        }
    }
}
