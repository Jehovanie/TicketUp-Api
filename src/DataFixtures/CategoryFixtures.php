<?php

namespace App\DataFixtures;

use App\Entity\Category;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Catégories d'événements.
 *
 * Les noms sont fixes et non tirés au sort : `$faker->word()` produisait des
 * catégories comme « ullam » ou « eveniet », illisibles dans l'interface et
 * inutilisables pour tester un filtre.
 */
class CategoryFixtures extends Fixture
{
    public const REFERENCE_PREFIX = 'category_';

    /** Nom => couleur d'affichage. */
    private const CATEGORIES = [
        'Concert' => '#7c3aed',
        'Festival' => '#dc2626',
        'Conférence' => '#2563eb',
        'Théâtre' => '#db2777',
        'Sport' => '#16a34a',
        'Humour' => '#f59e0b',
        'Cinéma' => '#0891b2',
        'Exposition' => '#a16207',
        'Atelier' => '#4f46e5',
        'Gala' => '#b45309',
        'Salon professionnel' => '#0f766e',
        'Jeunesse' => '#ea580c',
    ];

    public static function reference(string $name): string
    {
        return self::REFERENCE_PREFIX . $name;
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        foreach (self::CATEGORIES as $name => $color) {
            $category = (new Category())
                ->setName($name)
                ->setColor($color)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($category);
            $this->addReference(self::reference($name), $category);
        }

        $manager->flush();
    }
}
