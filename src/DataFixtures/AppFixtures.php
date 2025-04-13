<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Organizer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

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
            $organizer->setCreatedAt(new \DateTimeImmutable());
            $organizer->setUpdatedAt(new \DateTimeImmutable());
            $manager->persist($organizer);
            $organizers[] = $organizer;
        }

        // Génération des événements
        for ($i = 0; $i < 20; $i++) {
            $event = new Event();
            $event->setTitle($faker->sentence(3));
            $event->setDescription($faker->paragraph());
            $event->setStartedAt(new \DateTimeImmutable($faker->dateTimeBetween('now', '+1 year')->format('Y-m-d H:i:s')));
            $event->setEndAt(new \DateTimeImmutable($faker->dateTimeBetween('+1 day', '+1 year')->format('Y-m-d H:i:s')));
            $event->setLocalisation($faker->city());
            $event->setStatus($faker->boolean());
            $event->setCategory($faker->randomElement($categories));
            $event->setOrganizer($faker->randomElement($organizers));
            $event->setCreatedAt(new \DateTimeImmutable());
            $event->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($event);
        }

        $manager->flush();
    }
}
