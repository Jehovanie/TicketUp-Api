<?php

namespace App\Serializer\Denormalizer;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class CategoryDenormalizer implements DenormalizerInterface
{
    public function __construct(
        private IriConverterInterface $iriConverter,
        private EntityManagerInterface $em,
    ) {}

    public function supportsDenormalization($data, $type, $format = null, array $context = []): bool
    {
        return $type === Category::class && is_array($data) && isset($data['id']);
    }

    public function denormalize($data, $type, $format = null, array $context = []): mixed
    {
        // Transform { "id": 4 } into /api/Categorys/4
        $id = (int) $data['id'];
        $entity = $this->em->getRepository(Category::class)->find($id);

        if (!$entity) {
            throw new \Exception("Category with ID $id not found.");
        }

        return $this->iriConverter->getIriFromResource($entity); // ✅ ici c’est OK

        // return $this->iriConverter->getResourceFromIri(Category::class, ['id' => $dataId]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Category::class => true,
        ];
    }
}
