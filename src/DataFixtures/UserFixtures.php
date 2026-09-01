<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comptes de démonstration.
 *
 * Tous partagent le même mot de passe (`self::PASSWORD`) : ces données ne
 * servent qu'en développement, et retenir huit mots de passe différents pour
 * tester des rôles n'apporte rien.
 */
class UserFixtures extends Fixture
{
    public const REFERENCE_PREFIX = 'user_';

    /** Mot de passe commun à tous les comptes de démonstration. */
    public const PASSWORD = '12345678';

    public const FONDATEUR = 'admin@ticketup.mg';
    public const DIRIGEANT = 'jheo.ram@techmada.mg';

    /** Email => [prénom, nom, téléphone, rôles globaux]. */
    private const USERS = [
        // Fondateur de la plateforme : accès à toutes les organisations,
        // sans appartenir à aucune (c'est tout l'intérêt du rôle global).
        self::FONDATEUR => ['Fondateur', 'TicketUp', '+261 34 00 000 01', [User::ROLE_SUPER_ADMIN]],

        // Dirige deux structures indépendantes.
        self::DIRIGEANT => ['Jehovanie', 'Ramandrijoel', '+261 34 00 000 02', []],

        'lova.randria@techmada.mg' => ['Lova', 'Randrianasolo', '+261 34 00 000 03', []],
        'mamy.rabe@techmada.mg' => ['Mamy', 'Rabemananjara', '+261 34 00 000 04', []],
        'noro.andria@madagascar-events.mg' => ['Noro', 'Andrianina', '+261 32 00 000 05', []],
        'tiana.rasoa@zaikabe.mg' => ['Tiana', 'Rasoanaivo', '+261 33 00 000 06', []],
        'fanja.rako@antsika.mg' => ['Fanja', 'Rakotoarisoa', '+261 34 00 000 07', []],
        'jean.michel@baobabculture.mg' => ['Jean-Michel', 'Ravelo', '+261 32 00 000 08', []],
    ];

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public static function reference(string $email): string
    {
        return self::REFERENCE_PREFIX . $email;
    }

    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        foreach (self::USERS as $email => [$firstname, $lastname, $phone, $roles]) {
            $user = (new User())
                ->setEmail($email)
                ->setFirstname($firstname)
                ->setLastname($lastname)
                ->setPhone($phone)
                ->setLanguage('fr')
                ->setRoles($roles)
                ->setIsActive(new \DateTime())
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));

            $manager->persist($user);
            $this->addReference(self::reference($email), $user);
        }

        $manager->flush();
    }
}
