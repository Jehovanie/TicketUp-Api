<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
