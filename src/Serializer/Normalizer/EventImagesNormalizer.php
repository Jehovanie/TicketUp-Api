<?php

namespace App\Serializer\Normalizer;

use App\Entity\Event;
use App\Service\Storage\FileStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Ajoute `images` à la sérialisation d'un {@see Event} (groupes `events:lists`
 * et `events:details`) : `[{ "key": "events/42/…jpg", "url": "https://…" }]`.
 *
 * `url` est une URL présignée à durée limitée (S3_PRESIGNED_URL_TTL) : le
 * client ne doit pas la stocker, mais relire l'événement pour en obtenir une
 * nouvelle. La signature est calculée localement, sans appel au stockage.
 */
final class EventImagesNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'event_images_normalizer_already_called';
    private const GROUPS = ['events:lists', 'events:details'];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly LoggerInterface $logger,
    ) {}

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Event
            && !isset($context[self::ALREADY_CALLED][spl_object_id($data)])
            && [] !== array_intersect((array) ($context['groups'] ?? []), self::GROUPS);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        /** @var Event $data */
        $context[self::ALREADY_CALLED][spl_object_id($data)] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if (is_array($normalized)) {
            $normalized['images'] = array_map(
                fn (string $key) => ['key' => $key, 'url' => $this->url($key)],
                $data->getImages(),
            );
        }

        return $normalized;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Event::class => false];
    }

    private function url(string $key): ?string
    {
        try {
            return $this->storage->temporaryUrl($key);
        } catch (\Throwable $e) {
            $this->logger->error('Impossible de générer l’URL présignée d’une image d’événement.', ['key' => $key, 'exception' => $e]);

            return null;
        }
    }
}
