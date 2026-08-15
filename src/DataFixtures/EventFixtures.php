<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Location;
use App\Entity\Organizer;
use App\Entity\TicketType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;

/**
 * Événements et leur billetterie.
 *
 * Trois défauts de l'ancienne version sont corrigés ici :
 *
 * 1. la date de fin était tirée indépendamment de la date de début, si bien que
 *    des événements se terminaient avant d'avoir commencé ;
 * 2. les prix étaient des décimaux entre 10 et 150 alors que la colonne est un
 *    entier et la monnaie l'ariary — soit des billets à 39 Ar ;
 * 3. les titres et descriptions étaient du lorem ipsum, impossible à relire.
 *
 * Le jeu couvre aussi les cas limites que l'interface doit savoir afficher :
 * événements passés, en cours et à venir, billetterie vide, tarifs gratuits, et
 * quelques jauges volontairement dépassées.
 */
class EventFixtures extends Fixture implements DependentFixtureInterface
{
    private const NOMBRE_EVENEMENTS = 60;

    /** Modèles de titres par catégorie. */
    private const TITRES = [
        'Concert' => ['Concert de %s', 'Live Session — %s', 'Soirée acoustique %s'],
        'Festival' => ['Festival %s', '%s Open Air', 'Les Nuits de %s'],
        'Conférence' => ['Conférence %s', 'Rencontres %s', 'Sommet %s'],
        'Théâtre' => ['Pièce : %s', 'Théâtre — %s', 'Représentation %s'],
        'Sport' => ['Tournoi %s', 'Championnat %s', 'Grand Prix %s'],
        'Humour' => ['Stand-up : %s', 'Soirée rire %s', 'One man show %s'],
        'Cinéma' => ['Projection %s', 'Ciné-débat %s', 'Avant-première %s'],
        'Exposition' => ['Exposition %s', 'Galerie %s', 'Rétrospective %s'],
        'Atelier' => ['Atelier %s', 'Masterclass %s', 'Formation %s'],
        'Gala' => ['Gala %s', 'Dîner de gala %s', 'Soirée de prestige %s'],
        'Salon professionnel' => ['Salon %s', 'Forum %s', 'Journées %s'],
        'Jeunesse' => ['Village enfants %s', 'Fête de la jeunesse %s', 'Ateliers juniors %s'],
    ];

    /** Thèmes injectés dans les modèles de titres. */
    private const THEMES = [
        'Antananarivo', 'Tana', 'Madagascar', 'Océan Indien', 'Analakely',
        'Ivandry', 'Nosy Be', 'Antsirabe', 'Baobab', 'Sakalava',
        'Salegy', 'Tsapiky', 'Vakodrazana', 'Highlands', 'Zafimaniry',
    ];

    /**
     * Tarifs proposés : nom => [prix mini, prix maxi] en ariary.
     * Deux tarifs sont gratuits, pour vérifier l'affichage « Gratuit ».
     */
    private const TARIFS = [
        'Entrée générale' => [15000, 30000],
        'VIP' => [80000, 150000],
        'Premium' => [50000, 90000],
        'Carré Or' => [60000, 120000],
        'Gradin' => [10000, 20000],
        'Parterre' => [20000, 40000],
        'Balcon' => [25000, 45000],
        'Loge' => [100000, 200000],
        'Early Access' => [35000, 60000],
        'Pack Groupe' => [12000, 25000],
        'Espace Famille' => [10000, 20000],
        'Étudiant' => [8000, 15000],
        'Zone Presse' => [0, 0],
        'Accès PMR' => [0, 0],
    ];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        // Graine fixe : deux chargements produisent le même jeu, ce qui rend les
        // captures d'écran et les tests manuels comparables d'une fois sur l'autre.
        $faker->seed(2026);

        for ($index = 0; $index < self::NOMBRE_EVENEMENTS; $index++) {
            $category = $this->pickCategory($faker);
            $location = $this->pickLocation($faker);
            $organizer = $this->pickOrganizer($faker);

            $event = new Event();
            $event->setTitle($this->buildTitle($faker, (string) $category->getName()));
            $event->setDescription($this->buildDescription($faker, (string) $category->getName(), (string) $location->getName()));

            [$startedAt, $endAt] = $this->buildDates($faker, (string) $category->getName(), $index);
            $event->setStartedAt($startedAt);
            $event->setEndAt($endAt);

            // Quelques brouillons non publiés parmi les événements à venir.
            $event->setStatus($index % 11 !== 0);

            $event->setCategory($category);
            $event->setLocation($location);
            $event->setOrganizer($organizer);
            $event->setCreatedAt(new \DateTimeImmutable());
            $event->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($event);

            // Un événement sur douze n'a pas encore de billetterie : l'interface
            // doit afficher son état vide plutôt que de supposer au moins un tarif.
            if ($index % 12 !== 5) {
                $this->createTicketTypes($manager, $faker, $event, $index);
            }
        }

        $manager->flush();
    }

    /**
     * Dates cohérentes : la fin découle toujours du début, avec une durée qui
     * dépend du type d'événement (un concert dure des heures, une exposition
     * des semaines). L'index sert à garantir un panachage passé / en cours /
     * à venir, sans quoi un tirage purement aléatoire peut ne produire aucun
     * événement « en cours ».
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function buildDates(Generator $faker, string $categoryName, int $index): array
    {
        $dureeHeures = match ($categoryName) {
            'Festival' => $faker->numberBetween(48, 72),
            'Exposition' => $faker->numberBetween(24 * 7, 24 * 21),
            'Salon professionnel' => $faker->numberBetween(16, 48),
            'Conférence', 'Atelier' => $faker->numberBetween(3, 9),
            default => $faker->numberBetween(2, 5),
        };

        $now = new \DateTimeImmutable();

        $startedAt = match ($index % 5) {
            // Terminés
            0, 1 => $now->modify(sprintf('-%d days', $faker->numberBetween(20, 300))),
            // En cours : commencé récemment, se termine après aujourd'hui
            2 => $now->modify(sprintf('-%d hours', max(1, (int) ($dureeHeures / 2)))),
            // À venir
            default => $now->modify(sprintf('+%d days', $faker->numberBetween(3, 300))),
        };

        // Pour les événements « en cours », on s'assure que la durée dépasse le
        // temps déjà écoulé.
        if ($index % 5 === 2) {
            $dureeHeures = max($dureeHeures, (int) ($dureeHeures / 2) + 6);
        }

        return [$startedAt, $startedAt->modify(sprintf('+%d hours', $dureeHeures))];
    }

    private function buildTitle(Generator $faker, string $categoryName): string
    {
        $modeles = self::TITRES[$categoryName] ?? ['%s'];

        return sprintf(
            $faker->randomElement($modeles),
            $faker->randomElement(self::THEMES)
        ) . ' ' . $faker->numberBetween(2026, 2027);
    }

    private function buildDescription(Generator $faker, string $categoryName, string $locationName): string
    {
        return sprintf(
            '%s organisé à %s. %s Entrée sur présentation du billet électronique.',
            $categoryName,
            $locationName,
            $faker->sentence(12)
        );
    }

    /**
     * Billetterie : entre 1 et 4 tarifs, dont les quotas se répartissent une
     * fraction de la jauge du lieu. Un événement sur dix dépasse volontairement
     * la capacité, pour exercer l'alerte de sur-réservation.
     */
    private function createTicketTypes(ObjectManager $manager, Generator $faker, Event $event, int $index): void
    {
        $nombreTarifs = $faker->numberBetween(1, 4);
        $noms = $faker->randomElements(array_keys(self::TARIFS), $nombreTarifs);

        $capaciteLieu = (int) $event->getLocation()?->getSize();
        $surReservation = $index % 10 === 3;

        // Part de la salle réellement mise en vente.
        $capaciteVendue = $surReservation
            ? (int) round($capaciteLieu * $faker->randomFloat(2, 1.05, 1.3))
            : (int) round($capaciteLieu * $faker->randomFloat(2, 0.4, 1.0));

        $restant = max($nombreTarifs, $capaciteVendue);

        foreach ($noms as $position => $nom) {
            [$prixMin, $prixMax] = self::TARIFS[$nom];

            $estDernier = $position === $nombreTarifs - 1;
            $quota = $estDernier
                ? $restant
                : max(1, (int) round($restant / ($nombreTarifs - $position) * $faker->randomFloat(2, 0.6, 1.2)));

            $quota = min($quota, $restant - ($nombreTarifs - $position - 1));
            $restant -= $quota;

            $ticketType = (new TicketType())
                ->setName($nom)
                // Prix ronds à la centaine d'ariary près : personne n'affiche 23 417 Ar.
                ->setPrix((int) (round($faker->numberBetween($prixMin, $prixMax) / 500) * 500))
                ->setQuantiteMax(max(1, $quota))
                ->setEvent($event);

            $manager->persist($ticketType);
        }
    }

    private function pickCategory(Generator $faker): Category
    {
        return $this->getReference(
            CategoryFixtures::reference($faker->randomElement(CategoryFixtures::names())),
            Category::class
        );
    }

    private function pickLocation(Generator $faker): Location
    {
        return $this->getReference(
            LocationFixtures::reference($faker->randomElement(LocationFixtures::names())),
            Location::class
        );
    }

    private function pickOrganizer(Generator $faker): Organizer
    {
        return $this->getReference(
            OrganizerFixtures::reference($faker->randomElement(OrganizerFixtures::names())),
            Organizer::class
        );
    }

    public function getDependencies(): array
    {
        return [CategoryFixtures::class, LocationFixtures::class, OrganizerFixtures::class];
    }
}
