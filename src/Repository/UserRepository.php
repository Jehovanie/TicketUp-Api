<?php

namespace App\Repository;

use App\Entity\Organizer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }


    /**
     * Utilisateurs à inviter dans une organisation : correspondance partielle
     * (insensible à la casse) sur l'email, le prénom ou le nom, **hors membres
     * actuels** de cette organisation.
     *
     * @return User[]
     */
    public function searchInvitable(Organizer $organizer, string $term, int $limit): array
    {
        $like = '%' . addcslashes(mb_strtolower($term), '%_\\') . '%';

        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) LIKE :like OR LOWER(u.firstname) LIKE :like OR LOWER(u.lastname) LIKE :like')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\OrganizerMembership m WHERE m.user = u AND m.organizer = :organizer
            )')
            ->setParameter('like', $like)
            ->setParameter('organizer', $organizer)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function saveUser(User $user): void{
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
