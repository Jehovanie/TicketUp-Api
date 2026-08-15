<?php

namespace App\DataFixtures;

use App\Entity\Organizer;
use App\Entity\OrganizerMembership;
use App\Entity\User;
use App\Enum\OrganizerRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Rattachement des utilisateurs aux organisations, avec leur rôle.
 *
 * Le jeu de données illustre les cas qui comptent :
 * - Hery Rakoto est **responsable de deux organisations indépendantes** ;
 * - Tech Madagascar a une équipe complète (responsable, admin, membre) ;
 * - le fondateur n'appartient à **aucune** organisation, il y accède par son
 *   rôle global `ROLE_SUPER_ADMIN` ;
 * - deux organisations restent sans membre, pour tester l'amorçage.
 */
class MembershipFixtures extends Fixture implements DependentFixtureInterface
{
    /** [email, organisation, rôle] */
    private const MEMBERSHIPS = [
        // Une même personne à la tête de deux structures sans lien entre elles.
        [UserFixtures::DIRIGEANT, OrganizerFixtures::TECH_MADAGASCAR, OrganizerRole::OWNER],
        [UserFixtures::DIRIGEANT, OrganizerFixtures::MADAGASCAR_EVENTS, OrganizerRole::OWNER],

        // Équipe de Tech Madagascar.
        ['lova.randria@techmada.mg', OrganizerFixtures::TECH_MADAGASCAR, OrganizerRole::ADMIN],
        ['mamy.rabe@techmada.mg', OrganizerFixtures::TECH_MADAGASCAR, OrganizerRole::MEMBER],

        // Madagascar Events : une co-responsable, pour tester le retrait d'un
        // responsable quand il en reste un autre.
        ['noro.andria@madagascar-events.mg', OrganizerFixtures::MADAGASCAR_EVENTS, OrganizerRole::OWNER],

        // Autres structures, un responsable chacune.
        ['tiana.rasoa@zaikabe.mg', 'Zaikabe Production', OrganizerRole::OWNER],
        ['fanja.rako@antsika.mg', 'Antsika Prod', OrganizerRole::OWNER],
        ['jean.michel@baobabculture.mg', 'Baobab Culture', OrganizerRole::OWNER],

        // Une personne peut aussi être simple membre ailleurs.
        ['tiana.rasoa@zaikabe.mg', 'Tana Sound System', OrganizerRole::MEMBER],
    ];

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        foreach (self::MEMBERSHIPS as [$email, $organizerName, $role]) {
            /** @var User $user */
            $user = $this->getReference(UserFixtures::reference($email), User::class);
            /** @var Organizer $organizer */
            $organizer = $this->getReference(OrganizerFixtures::reference($organizerName), Organizer::class);

            $membership = (new OrganizerMembership())
                ->setUser($user)
                ->setOrganizer($organizer)
                ->setRole($role)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $manager->persist($membership);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class, OrganizerFixtures::class];
    }
}
