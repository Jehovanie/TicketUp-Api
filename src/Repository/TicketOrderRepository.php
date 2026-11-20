<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\TicketOrder;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketOrder>
 */
class TicketOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketOrder::class);
    }

    public function findOneByReference(string $reference): ?TicketOrder
    {
        $order = $this->findOneBy(['reference' => $reference]);

        if ($order !== null) {
            $this->loadDetails([$order]);
        }

        return $order;
    }

    public function findOneByUserAndIdempotencyKey(User $user, string $key): ?TicketOrder
    {
        return $this->findOneBy(['user' => $user, 'idempotencyKey' => $key]);
    }

    public function referenceExists(string $reference): bool
    {
        return $this->count(['reference' => $reference]) > 0;
    }

    /**
     * Commandes de l'acheteur, de la plus récente à la plus ancienne.
     *
     * @param OrderStatus[] $statuses filtre ; vide = tous les statuts
     * @return TicketOrder[]
     */
    public function findByUser(User $user, int $limit, int $offset, array $statuses = []): array
    {
        return $this->page($this->filtered('user', $user, $statuses), $limit, $offset);
    }

    /** @param OrderStatus[] $statuses */
    public function countByUser(User $user, array $statuses = []): int
    {
        return $this->countFiltered($this->filtered('user', $user, $statuses));
    }

    /** @return array<string, int> nombre de commandes de l'acheteur par statut (tous les statuts, 0 compris) */
    public function countsByStatusForUser(User $user): array
    {
        return $this->countsByStatus('user', $user);
    }

    /**
     * Commandes d'un événement, de la plus récente à la plus ancienne.
     *
     * @param OrderStatus[] $statuses filtre ; vide = tous les statuts
     * @return TicketOrder[]
     */
    public function findByEvent(Event $event, int $limit, int $offset, array $statuses = []): array
    {
        return $this->page($this->filtered('event', $event, $statuses), $limit, $offset);
    }

    /** @param OrderStatus[] $statuses */
    public function countByEvent(Event $event, array $statuses = []): int
    {
        return $this->countFiltered($this->filtered('event', $event, $statuses));
    }

    /** @return array<string, int> nombre de commandes de l'événement par statut (tous les statuts, 0 compris) */
    public function countsByStatusForEvent(Event $event): array
    {
        return $this->countsByStatus('event', $event);
    }

    /** @param OrderStatus[] $statuses */
    private function filtered(string $field, object $owner, array $statuses): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere(sprintf('o.%s = :owner', $field))
            ->setParameter('owner', $owner);

        if ($statuses !== []) {
            $qb->andWhere('o.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (OrderStatus $s) => $s->value, $statuses));
        }

        return $qb;
    }

    private function countFiltered(QueryBuilder $qb): int
    {
        return (int) $qb->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, int> */
    private function countsByStatus(string $field, object $owner): array
    {
        $rows = $this->filtered($field, $owner, [])
            ->select('o.status AS status', 'COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases()), 0);
        foreach ($rows as $row) {
            $status = $row['status'] instanceof OrderStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return TicketOrder[] */
    private function page(QueryBuilder $qb, int $limit, int $offset): array
    {
        $orders = $qb
            ->addSelect('e')
            ->join('o.event', 'e')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();

        $this->loadDetails($orders);

        return $orders;
    }

    /**
     * Charge lignes et billets d'un lot de commandes en une requête.
     *
     * Les joindre dans la requête paginée fausserait `setMaxResults` (une ligne
     * SQL par billet). La seconde requête hydrate les collections des instances
     * déjà gérées : la présentation ne déclenche plus de requête par commande.
     *
     * @param TicketOrder[] $orders
     */
    private function loadDetails(array $orders): void
    {
        if ($orders === []) {
            return;
        }

        $this->createQueryBuilder('o')
            ->select('o', 'i', 't')
            ->leftJoin('o.items', 'i')
            ->leftJoin('i.tickets', 't')
            ->andWhere('o IN (:orders)')
            ->setParameter('orders', $orders)
            ->getQuery()
            ->getResult();
    }
}
