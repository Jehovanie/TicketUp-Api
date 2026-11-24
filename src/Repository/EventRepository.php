<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\EventFilters;
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
     * Liste filtrée et triée — **une seule requête** pour `/api/events`,
     * `/api/events/me` et `/api/organizers/{id}/events`.
     *
     * `$scope` restreint aux événements des organisations dont cet utilisateur
     * est membre. Le lien n'est pas direct (un événement appartient à une
     * organisation, `OrganizerMembership` rattache la personne) d'où la double
     * jointure ; l'unicité (utilisateur, organisation) garantit une seule ligne
     * d'appartenance : pas de doublon, donc pas de `DISTINCT`.
     *
     * Aucun filtre implicite sur `status` : c'est à l'appelant de décider si les
     * brouillons sont visibles (`EventFilters::$published`).
     *
     * @return Event[]
     */
    public function findFiltered(EventFilters $filters, ?User $scope, int $limit, int $offset = 0): array
    {
        // Catégorie et lieu sont dans `events:lists` : les charger ici évite deux
        // requêtes par événement à la sérialisation. Les billets, eux, sont une
        // collection — les joindre fausserait `setMaxResults`.
        $qb = $this->filteredQuery($filters, $scope)
            ->addSelect('c', 'l')
            ->leftJoin('e.category', 'c')
            ->leftJoin('e.location', 'l');

        [$field, $direction] = EventFilters::SORTS[$filters->sort];

        return $qb
            ->orderBy('e.' . $field, $direction)
            ->addOrderBy('e.id', 'DESC') // départage les ex æquo : pagination stable
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(EventFilters $filters, ?User $scope): int
    {
        return (int) $this->filteredQuery($filters, $scope)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filteredQuery(EventFilters $filters, ?User $scope): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');

        if ($scope !== null) {
            $qb->join('e.organizer', 'o')
                ->join('o.memberships', 'm')
                ->andWhere('m.user = :scope')
                ->setParameter('scope', $scope);
        }

        if ($filters->organizerId !== null) {
            $qb->andWhere('IDENTITY(e.organizer) = :organizer')->setParameter('organizer', $filters->organizerId);
        }

        if ($filters->categoryId !== null) {
            $qb->andWhere('IDENTITY(e.category) = :category')->setParameter('category', $filters->categoryId);
        }

        if ($filters->title !== null) {
            $qb->andWhere('LOWER(e.title) LIKE :title')
                ->setParameter('title', '%' . addcslashes(mb_strtolower($filters->title), '%_\\') . '%');
        }

        if ($filters->published !== null) {
            $qb->andWhere('e.status = :published')->setParameter('published', $filters->published);
        }

        // Bornes sur le début de l'événement, incluses ; `endDate` couvre toute la
        // journée (strictement avant minuit le lendemain).
        if ($filters->startDate !== null) {
            $qb->andWhere('e.startedAt >= :startDate')->setParameter('startDate', $filters->startDate);
        }
        if ($filters->endDate !== null) {
            $qb->andWhere('e.startedAt < :endDateExclusive')->setParameter('endDateExclusive', $filters->endDate->modify('+1 day'));
        }

        $now = new \DateTimeImmutable();
        match ($filters->period) {
            EventFilters::PERIOD_UPCOMING => $qb->andWhere('e.startedAt > :now')->setParameter('now', $now),
            EventFilters::PERIOD_ONGOING => $qb->andWhere('e.startedAt <= :now AND e.endAt >= :now')->setParameter('now', $now),
            EventFilters::PERIOD_PAST => $qb->andWhere('e.endAt < :now')->setParameter('now', $now),
            default => null,
        };

        return $qb;
    }
}
