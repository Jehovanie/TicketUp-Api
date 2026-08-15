<?php

namespace App\DataFixtures;

use App\Entity\Location;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Lieux d'accueil, avec des capacités plausibles.
 *
 * La capacité sert de garde-fou à la billetterie (l'interface d'administration
 * compare les quotas de billets à la jauge de la salle) : des valeurs réalistes
 * et contrastées — de 250 à 22 000 places — rendent ce contrôle testable.
 */
class LocationFixtures extends Fixture
{
    public const REFERENCE_PREFIX = 'location_';

    /** Nom => capacité. */
    private const LOCATIONS = [
        'Stade Barea Mahamasina' => 22000,
        'Palais des Sports Mahamasina' => 7000,
        'Arena Ivandry' => 1500,
        'Jardin d’Antaninarenina' => 1200,
        'Espace Dera Ambohijatovo' => 900,
        'Centre de Conférences International Ivato' => 800,
        'Canal Olympia Andohatapenaka' => 600,
        'Institut Français de Madagascar Analakely' => 450,
        'Le Glacier Analakely' => 400,
        'Hôtel Carlton Anosy' => 350,
        'Alliance Française Andavamamba' => 300,
        'CEMES Antanimena' => 250,
    ];

    public static function reference(string $name): string
    {
        return self::REFERENCE_PREFIX . $name;
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::LOCATIONS);
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        foreach (self::LOCATIONS as $name => $size) {
            $location = (new Location())
                ->setName($name)
                ->setSize($size)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($location);
            $this->addReference(self::reference($name), $location);
        }

        $manager->flush();
    }
}
