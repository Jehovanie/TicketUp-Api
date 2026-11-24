<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Organizer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    //    /**
    //     * @return Event[] Returns an array of Event objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Event
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    /**
     * Recherche des événements par catégorie
     * 
     * @param int $categoryId
     * @return Event[]
     */
    public function findByCategory(int $categoryId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.category = :categoryId')
            ->andWhere('e.status = :status')
            ->setParameter('categoryId', $categoryId)
            ->setParameter('status', true)
            ->orderBy('e.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche des événements avec des critères multiples
     * 
     * @param array $criteria
     * @return Event[]
     */
    public function searchEvents(array $criteria): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.status = :status')
            ->setParameter('status', true);

        if (isset($criteria['categoryId'])) {
            $qb->andWhere('e.category = :categoryId')
                ->setParameter('categoryId', $criteria['categoryId']);
        }

        if (isset($criteria['title'])) {
            $qb->andWhere('e.title LIKE :title')
                ->setParameter('title', '%' . $criteria['title'] . '%');
        }

        if (isset($criteria['startDate'])) {
            $qb->andWhere('e.startedAt >= :startDate')
                ->setParameter('startDate', $criteria['startDate']);
        }

        if (isset($criteria['endDate'])) {
            $qb->andWhere('e.startedAt <= :endDate')
                ->setParameter('endDate', $criteria['endDate']);
        }

        $qb->orderBy('e.startedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Événements des organisations auxquelles l'utilisateur appartient.
     *
     * Le lien n'est pas direct : un événement appartient à une organisation, et
     * c'est `OrganizerMembership` qui rattache la personne à cette organisation.
     * D'où la double jointure. L'unicité (utilisateur, organisation) garantit une
     * seule ligne d'appartenance par organisation : pas de doublon d'événement,
     * donc pas de `DISTINCT` à payer.
     *
     * Aucun filtre sur `status` : c'est une vue de gestion, les brouillons de son
     * organisation doivent rester visibles à celui qui les a écrits.
     *
     * @return Event[]
     */
    public function findByUser(User $user, ?int $limit = null, int $offset = 0): array
    {
        // Catégorie et lieu sont dans le groupe `events:lists` : les charger ici
        // évite deux requêtes par événement au moment de la sérialisation. Les
        // billets, eux, sont une collection — la joindre fausserait `setMaxResults`.
        $qb = $this->createQueryBuilder('e')
            ->addSelect('c', 'l')
            ->leftJoin('e.category', 'c')
            ->leftJoin('e.location', 'l')
            ->join('e.organizer', 'o')
            ->join('o.memberships', 'm')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit)->setFirstResult(max(0, $offset));
        }

        return $qb->getQuery()->getResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.organizer', 'o')
            ->join('o.memberships', 'm')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public const PERIOD_UPCOMING = 'upcoming';
    public const PERIOD_ONGOING = 'ongoing';
    public const PERIOD_PAST = 'past';

    /**
     * Événements d'une organisation, brouillons compris (vue de gestion).
     *
     * @param ?bool   $published `true` = publiés, `false` = brouillons, `null` = tous
     * @param ?string $period    `PERIOD_*` ou `null` : à venir (début futur), en cours, passé (fin révolue)
     *
     * @return Event[]
     */
    public function findByOrganizer(Organizer $organizer, ?bool $published, ?string $period, int $limit, int $offset): array
    {
        $qb = $this->organizerQuery($organizer, $published, $period)
            ->addSelect('c', 'l')
            ->leftJoin('e.category', 'c')
            ->leftJoin('e.location', 'l')
            // À venir : le plus proche d'abord ; sinon le plus récent d'abord.
            ->orderBy('e.startedAt', $period === self::PERIOD_UPCOMING ? 'ASC' : 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset));

        return $qb->getQuery()->getResult();
    }

    public function countByOrganizer(Organizer $organizer, ?bool $published, ?string $period): int
    {
        return (int) $this->organizerQuery($organizer, $published, $period)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function organizerQuery(Organizer $organizer, ?bool $published, ?string $period): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.organizer = :organizer')
            ->setParameter('organizer', $organizer);

        if ($published !== null) {
            $qb->andWhere('e.status = :published')->setParameter('published', $published);
        }

        $now = new \DateTimeImmutable();
        match ($period) {
            self::PERIOD_UPCOMING => $qb->andWhere('e.startedAt > :now')->setParameter('now', $now),
            self::PERIOD_ONGOING => $qb->andWhere('e.startedAt <= :now AND e.endAt >= :now')->setParameter('now', $now),
            self::PERIOD_PAST => $qb->andWhere('e.endAt < :now')->setParameter('now', $now),
            default => null,
        };

        return $qb;
    }
}
