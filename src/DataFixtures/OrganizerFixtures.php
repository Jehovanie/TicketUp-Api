<?php

namespace App\DataFixtures;

use App\Entity\Organizer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Organisations qui produisent les événements.
 *
 * « Tech Madagascar » et « Madagascar Events » sont volontairement présentes :
 * ce sont deux structures indépendantes dirigées par la même personne, le cas
 * qui justifie la relation many-to-many entre `User` et `Organizer`.
 */
class OrganizerFixtures extends Fixture
{
    public const REFERENCE_PREFIX = 'organizer_';

    public const TECH_MADAGASCAR = 'Tech Madagascar';
    public const MADAGASCAR_EVENTS = 'Madagascar Events';

    /** Nom => [email, téléphone, site]. */
    private const ORGANIZERS = [
        self::TECH_MADAGASCAR => ['contact@techmada.mg', '+261 34 12 345 67', 'https://techmada.mg'],
        self::MADAGASCAR_EVENTS => ['hello@madagascar-events.mg', '+261 32 45 678 90', 'https://madagascar-events.mg'],
        'Zaikabe Production' => ['prod@zaikabe.mg', '+261 33 11 223 34', 'https://zaikabe.mg'],
        'Antsika Prod' => ['contact@antsika.mg', '+261 34 98 765 43', 'https://antsika.mg'],
        'Baobab Culture' => ['info@baobabculture.mg', '+261 32 77 889 90', 'https://baobabculture.mg'],
        'Tana Sound System' => ['booking@tanasound.mg', '+261 34 55 667 78', 'https://tanasound.mg'],
        'Vibes Mada' => ['contact@vibesmada.mg', '+261 33 22 334 45', 'https://vibesmada.mg'],
        'Sarobidy Événements' => ['contact@sarobidy-events.mg', '+261 32 66 778 89', 'https://sarobidy-events.mg'],
    ];

    public static function reference(string $name): string
    {
        return self::REFERENCE_PREFIX . $name;
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::ORGANIZERS);
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        foreach (self::ORGANIZERS as $name => [$email, $phone, $website]) {
            $organizer = (new Organizer())
                ->setName($name)
                ->setEmail($email)
                ->setPhone($phone)
                ->setWebsite($website)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($organizer);
            $this->addReference(self::reference($name), $organizer);
        }

        $manager->flush();
    }
}
