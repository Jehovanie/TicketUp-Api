<?php

namespace App\DataFixtures;

use App\Entity\Organizer;
use App\Entity\OrganizerPaymentMethod;
use App\Enum\MobileMoneyProvider;
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

    /**
     * Nom => comptes mobile money [opérateur, numéro, titulaire].
     *
     * Chaque organisation en a au moins un (règle BR-ORG-10) ; les numéros sont
     * normalisés par `MobileMoneyProvider::normalizePhone()`, comme à l'API.
     * Les cas couverts : un seul opérateur, deux, les trois, et un titulaire
     * (personne physique) différent du nom de l'organisation.
     */
    private const PAYMENT_METHODS = [
        self::TECH_MADAGASCAR => [
            [MobileMoneyProvider::MVOLA, '034 12 345 67', 'RAKOTO Hery'],
            [MobileMoneyProvider::ORANGE_MONEY, '032 12 345 67', 'RAKOTO Hery'],
            [MobileMoneyProvider::AIRTEL_MONEY, '033 12 345 67', 'Tech Madagascar SARL'],
        ],
        self::MADAGASCAR_EVENTS => [
            [MobileMoneyProvider::MVOLA, '038 45 678 90', 'RAKOTO Hery'],
            [MobileMoneyProvider::ORANGE_MONEY, '+261 37 45 678 90', 'Madagascar Events'],
        ],
        'Zaikabe Production' => [[MobileMoneyProvider::AIRTEL_MONEY, '033 11 223 34', 'RASOANAIVO Miora']],
        'Antsika Prod' => [[MobileMoneyProvider::MVOLA, '034 98 765 43', 'ANDRIAMAMPIANINA Tojo']],
        'Baobab Culture' => [[MobileMoneyProvider::ORANGE_MONEY, '032 77 889 90', 'Association Baobab Culture']],
        'Tana Sound System' => [
            [MobileMoneyProvider::MVOLA, '034 55 667 78', 'RAZAFY Njaka'],
            [MobileMoneyProvider::AIRTEL_MONEY, '033 55 667 78', 'RAZAFY Njaka'],
        ],
        'Vibes Mada' => [[MobileMoneyProvider::MVOLA, '034 22 334 45', 'RANDRIA Faly']],
        'Sarobidy Événements' => [[MobileMoneyProvider::ORANGE_MONEY, '032 66 778 89', 'RAKOTOMALALA Voahangy']],
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

            foreach (self::PAYMENT_METHODS[$name] as [$provider, $number, $holder]) {
                $organizer->addPaymentMethod(
                    (new OrganizerPaymentMethod())
                        ->setProvider($provider)
                        ->setPhoneNumber($provider->normalizePhone($number))
                        ->setAccountName($holder)
                );
            }

            $manager->persist($organizer);
            $this->addReference(self::reference($name), $organizer);
        }

        $manager->flush();
    }
}
