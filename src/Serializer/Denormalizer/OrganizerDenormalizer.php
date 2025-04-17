<?php

namespace App\Serializer\Denormalizer;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Organizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class OrganizerDenormalizer implements DenormalizerInterface
{
    public function __construct(
        private IriConverterInterface $iriConverter,
        private EntityManagerInterface $em,
    ) {}

    public function supportsDenormalization($data, $type, $format = null, array $context = []): bool
    {
        return $type === Organizer::class && is_array($data) && isset($data['id']);
    }

    public function denormalize($data, $type, $format = null, array $context = []): mixed
    {
        // Transform { "id": 4 } into /api/Categorys/4
        $id = (int) $data['id'];
        $entity = $this->em->getRepository(Organizer::class)->find($id);

        if (!$entity) {
            throw new \Exception("Organizer with ID $id not found.");
        }

        return $this->iriConverter->getIriFromResource($entity); // ✅ ici c’est OK
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Organizer::class => true,
        ];
    }
}
