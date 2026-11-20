<?php

namespace App\DataFixtures;

use App\Entity\Event;
use App\Entity\TicketType;
use App\Entity\User;
use App\Service\Order\OrderService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Commandes de démonstration.
 *
 * Elles passent par `OrderService`, comme en vrai : le compteur
 * `ticket_type.quantite_reservee` reste ainsi cohérent avec les commandes, ce
 * qu'une insertion directe ne garantirait pas.
 *
 * Le jeu couvre les états que l'interface doit savoir afficher : commande
 * payée avec billets, en attente (réservation en cours), annulée, expirée,
 * gratuite, et un tarif vendu jusqu'à la dernière place (« Complet »).
 *
 * Les acheteurs sont les comptes de démonstration (hors super administrateur),
 * les événements ceux encore en vente — lus en base plutôt que par référence,
 * pour ne pas dépendre du détail des autres fixtures.
 */
class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    private const EVENTS_WITH_ORDERS = 10;

    public function __construct(private readonly OrderService $orderService)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $buyers = array_values(array_filter(
            $manager->getRepository(User::class)->findBy([], ['id' => 'ASC']),
            static fn (User $user) => !$user->isSuperAdmin()
        ));
        $events = $this->eventsOnSale($manager);

        if ($buyers === [] || $events === []) {
            return;
        }

        foreach (array_slice($events, 0, self::EVENTS_WITH_ORDERS) as $index => $event) {
            $buyer = $buyers[$index % count($buyers)];
            $ticketType = $this->ticketTypeWithStock($event, 2);

            if ($ticketType === null) {
                continue;
            }

            $order = $this->orderService->place($buyer, $event->getId(), [
                ['ticketTypeId' => $ticketType->getId(), 'quantity' => 2],
            ]);

            // Une commande gratuite est déjà payée.
            if ($order->getTotalAmount() === 0) {
                continue;
            }

            match ($index % 5) {
                0, 3 => $this->orderService->markAsPaid($order),
                1 => null, // reste en attente : réservation en cours
                2 => $this->orderService->cancel($order, $buyer),
                4 => $this->expire($manager, $order->getId()),
            };
        }

        $this->sellOut($events, $buyers);
    }

    /**
     * Vend toutes les places du plus petit tarif trouvé, par commandes de 10,
     * pour qu'au moins un tarif s'affiche « Complet ».
     *
     * @param Event[] $events
     * @param User[]  $buyers
     */
    private function sellOut(array $events, array $buyers): void
    {
        $smallest = null;
        foreach ($events as $event) {
            foreach ($event->getTicketType() as $ticketType) {
                if ($ticketType->getPrix() > 0
                    && $ticketType->getQuantiteDisponible() > 0
                    && $ticketType->getQuantiteDisponible() <= 60
                    && ($smallest === null || $ticketType->getQuantiteDisponible() < $smallest->getQuantiteDisponible())) {
                    $smallest = $ticketType;
                }
            }
        }

        if ($smallest === null) {
            return;
        }

        $buyerIndex = 0;
        while (($remaining = $smallest->getQuantiteDisponible()) > 0) {
            $order = $this->orderService->place(
                $buyers[$buyerIndex++ % count($buyers)],
                $smallest->getEvent()->getId(),
                [['ticketTypeId' => $smallest->getId(), 'quantity' => min($remaining, OrderService::MAX_TICKETS_PER_ORDER)]]
            );
            $this->orderService->markAsPaid($order);
        }
    }

    /** Simule une commande non payée à temps, puis la purge planifiée. */
    private function expire(ObjectManager $manager, int $orderId): void
    {
        $manager->getConnection()->executeStatement(
            'UPDATE ticket_order SET expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $orderId]
        );

        $this->orderService->expirePending();
    }

    /** @return Event[] événements publiés, non terminés, avec billetterie */
    private function eventsOnSale(ObjectManager $manager): array
    {
        $events = $manager->getRepository(Event::class)->findBy(['status' => true], ['id' => 'ASC']);
        $now = new \DateTimeImmutable();

        return array_values(array_filter(
            $events,
            static fn (Event $event) => $event->getEndAt() > $now && !$event->getTicketType()->isEmpty()
        ));
    }

    private function ticketTypeWithStock(Event $event, int $quantity): ?TicketType
    {
        foreach ($event->getTicketType() as $ticketType) {
            if ($ticketType->getQuantiteDisponible() >= $quantity) {
                return $ticketType;
            }
        }

        return null;
    }

    public function getDependencies(): array
    {
        return [EventFixtures::class, MembershipFixtures::class];
    }
}
