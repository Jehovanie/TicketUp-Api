<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Location;
use App\Entity\Organizer;
use App\Entity\TicketType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $typePlaces = [
            'VIP',
            'Fanzone',
            'Standard',
            'Backstage',
            'Carré Or',
            'Balcon',
            'Gradin',
            'Parterre',
            'Loge',
            'Front Stage',
            'Premium',
            'Entrée Libre',
            'Early Access',
            'After Party',
            'Pack Groupe',
            'Zone Presse',
            'Handicapé',
            'Espace Famille',
        ];


        // Génération des catégories
        $categories = [];
        for ($i = 0; $i < 5; $i++) {
            $category = new Category();
            $category->setName($faker->word());
            $category->setColor($faker->hexColor());
            $category->setCreatedAt(new \DateTimeImmutable());
            $category->setUpdatedAt(new \DateTimeImmutable());
            $manager->persist($category);
            $categories[] = $category;
        }

        // Génération des organisateurs
        $organizers = [];
        for ($i = 0; $i < 10; $i++) {
            $organizer = new Organizer();
            $organizer->setName($faker->company());
            $organizer->setEmail($faker->companyEmail());
            $organizer->setPhone($faker->phoneNumber());
            $organizer->setWebsite($faker->url());
            // $organizer->setAddress($faker->address());
            $organizer->setCreatedAt(new \DateTimeImmutable());
            $organizer->setUpdatedAt(new \DateTimeImmutable());
            $manager->persist($organizer);
            $organizers[] = $organizer;
        }

        $locations = [];
        for ($i = 0; $i < 5; $i++) {
            $location = new Location();
            $location->setName($faker->word());
            $location->setSize($faker->numberBetween(50, 500));
            $location->setCreatedAt(new \DateTimeImmutable());
            $location->setUpdatedAt(new \DateTimeImmutable());
            $manager->persist($location);
            $locations[] = $location;
        }

        // Génération des événements
        for ($i = 0; $i < 20; $i++) {
            $event = new Event();
            $event->setTitle($faker->sentence(3));
            $event->setDescription($faker->paragraph());
            $event->setStartedAt(new \DateTimeImmutable($faker->dateTimeBetween('now', '+1 year')->format('Y-m-d H:i:s')));
            $event->setEndAt(new \DateTimeImmutable($faker->dateTimeBetween('+1 day', '+1 year')->format('Y-m-d H:i:s')));
            $event->setStatus($faker->boolean());
            $event->setLocation($faker->randomElement($locations));
            $event->setCategory($faker->randomElement($categories));
            $event->setOrganizer($faker->randomElement($organizers));
            $event->setCreatedAt(new \DateTimeImmutable());
            $event->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($event);

            // Sélectionner entre 1 et 5 types de place au hasard
            $nombreTypes = rand(1, 5);
            $typesChoisis = $faker->randomElements($typePlaces, $nombreTypes);

            $locationSize = $event->getLocation()->getSize();
            $remainingCapacity = $locationSize;
            $quantities = [];

            // Répartir équitablement la capacité
            for ($j = 0; $j < $nombreTypes; $j++) {
                // Dernier ticket => prend le reste
                if ($j === $nombreTypes - 1) {
                    $quantities[] = $remainingCapacity;
                } else {
                    // Répartition aléatoire entre 1 et (reste - nb tickets restants)
                    $max = $remainingCapacity - ($nombreTypes - $j - 1);
                    $qty = rand(1, $max);
                    $quantities[] = $qty;
                    $remainingCapacity -= $qty;
                }
            }

            foreach ($typesChoisis as $index => $nomType) {
                $typePlace = new TicketType();
                $typePlace->setName($nomType);
                $typePlace->setPrix($faker->randomFloat(2, 10, 150));
                $typePlace->setQuantiteMax($quantities[$index]);
                $typePlace->setEvent($event);

                $manager->persist($typePlace);
            }
        }

        $manager->flush();
    }
}
