<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\TicketOrder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /** @return TicketOrder[] commandes de l'acheteur, de la plus récente à la plus ancienne */
    public function findByUser(User $user, int $limit, int $offset): array
    {
        return $this->page(['user' => $user], $limit, $offset);
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /** @return TicketOrder[] commandes d'un événement, de la plus récente à la plus ancienne */
    public function findByEvent(Event $event, int $limit, int $offset): array
    {
        return $this->page(['event' => $event], $limit, $offset);
    }

    public function countByEvent(Event $event): int
    {
        return $this->count(['event' => $event]);
    }

    /** @return TicketOrder[] */
    private function page(array $criteria, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('o')
            ->addSelect('e')
            ->join('o.event', 'e')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset));

        foreach ($criteria as $field => $value) {
            $qb->andWhere(sprintf('o.%s = :%s', $field, $field))->setParameter($field, $value);
        }

        $orders = $qb->getQuery()->getResult();
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
