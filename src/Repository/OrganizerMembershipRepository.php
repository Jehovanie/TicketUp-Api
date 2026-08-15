<?php

namespace App\Repository;

use App\Entity\Organizer;
use App\Entity\OrganizerMembership;
use App\Entity\User;
use App\Enum\OrganizerRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizerMembership>
 */
class OrganizerMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizerMembership::class);
    }

    public function save(OrganizerMembership $membership, bool $flush = true): void
    {
        $this->getEntityManager()->persist($membership);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(OrganizerMembership $membership, bool $flush = true): void
    {
        $this->getEntityManager()->remove($membership);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByUserAndOrganizer(User $user, Organizer $organizer): ?OrganizerMembership
    {
        return $this->findOneBy(['user' => $user, 'organizer' => $organizer]);
    }

    /**
     * Appartenances d'un utilisateur, l'organisation chargée dans la foulée pour
     * éviter une requête par ligne au moment de l'affichage.
     *
     * `$limit` à `null` renvoie tout : la pagination n'a de sens que pour les
     * listes exposées par l'API, pas pour les contrôles d'accès internes.
     *
     * @return OrganizerMembership[]
     */
    public function findByUser(User $user, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('o')
            ->join('m.organizer', 'o')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.name', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit)->setFirstResult(max(0, $offset));
        }

        return $qb->getQuery()->getResult();
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Membres d'une organisation, triés par niveau de rôle décroissant puis par
     * nom : les responsables apparaissent en tête.
     *
     * @return OrganizerMembership[]
     */
    public function findByOrganizer(Organizer $organizer): array
    {
        $memberships = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.organizer = :organizer')
            ->setParameter('organizer', $organizer)
            ->getQuery()
            ->getResult();

        usort(
            $memberships,
            static fn (OrganizerMembership $a, OrganizerMembership $b) =>
                [$b->getRole()->level(), strtolower((string) $a->getUser()?->getLastname())]
                <=> [$a->getRole()->level(), strtolower((string) $b->getUser()?->getLastname())]
        );

        return $memberships;
    }

    /** @return OrganizerMembership[] */
    public function findByOrganizerAndRole(Organizer $organizer, OrganizerRole $role): array
    {
        return $this->findBy(['organizer' => $organizer, 'role' => $role]);
    }

    public function countByOrganizerAndRole(Organizer $organizer, OrganizerRole $role): int
    {
        return $this->count(['organizer' => $organizer, 'role' => $role]);
    }
}
