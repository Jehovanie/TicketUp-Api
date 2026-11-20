<?php

namespace App\Tests\Integration\Order;

use App\Enum\OrderStatus;
use App\Tests\Support\OrderTestCase;

/**
 * Cycle de vie après l'envoi : annulation, paiement, expiration.
 * Référence : docs/database/design-orders.md § 4 (diagramme d'états).
 */
final class OrderLifecycleTest extends OrderTestCase
{
    // ------------------------------------------------------------ annulation

    public function testLAcheteurAnnuleUneCommandeEnAttenteEtRendLesPlaces(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $order = $this->placeOrder($buyer, $event, $ticketType, 3);

        $cancelled = $this->orders()->cancel($order, $buyer);

        self::assertSame(OrderStatus::CANCELLED, $cancelled->getStatus());
        self::assertNotNull($cancelled->getCancelledAt());
        self::assertSame('cancelled', $this->orderStatus($order));
        self::assertSame(0, $this->reserved($ticketType));
    }

    public function testUnAutreUtilisateurNePeutPasAnnulerEtNeVoitPasLaCommande(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $order = $this->placeOrder($this->data->user(), $event, $ticketType, 2);

        $this->assertOrderError(404, fn () => $this->orders()->cancel($order, $this->data->user()));

        self::assertSame('pending', $this->orderStatus($order));
        self::assertSame(2, $this->reserved($ticketType));
    }

    public function testUneCommandePayeeNePeutPasEtreAnnuleeParLAcheteur(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $gratuit = $this->data->ticketType($event, 'Zone Presse', 0);
        $order = $this->placeOrder($buyer, $event, $gratuit, 1); // gratuite ⇒ payée

        $this->assertOrderError(409, fn () => $this->orders()->cancel($order, $buyer));

        self::assertSame('paid', $this->orderStatus($order));
        self::assertSame(1, $this->reserved($gratuit));
    }

    public function testUneCommandeExpireeNePeutPasEtreAnnulee(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $order = $this->placeOrder($buyer, $event, $ticketType, 2);
        $this->orders()->expirePending(new \DateTimeImmutable('+1 hour'));

        $this->assertOrderError(409, fn () => $this->orders()->cancel($order, $buyer));

        self::assertSame(0, $this->reserved($ticketType), 'Les places ne sont pas rendues deux fois.');
    }

    // ------------------------------------------------------------- paiement

    public function testLePaiementConfirmePasseLaCommandeAPayeeEtEmetUnBilletParPlace(): void
    {
        $event = $this->data->event();
        $standard = $this->data->ticketType($event, 'Entrée générale', 20000);
        $vip = $this->data->ticketType($event, 'VIP', 50000);
        $order = $this->orders()->place($this->data->user(), $event->getId(), [
            self::line($standard, 2),
            self::line($vip, 1),
        ]);

        $paid = $this->orders()->markAsPaid($order);

        self::assertSame(OrderStatus::PAID, $paid->getStatus());
        self::assertNotNull($paid->getPaidAt());
        self::assertSame('paid', $this->orderStatus($order));
        self::assertSame(3, $this->countTicketsOf($order));
        self::assertSame(2, $this->reserved($standard), 'Les places restent prises une fois payées.');
        self::assertSame(1, $this->reserved($vip));
    }

    public function testConfirmerDeuxFoisLePaiementNEmetPasDeBilletsEnDouble(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $order = $this->placeOrder($this->data->user(), $event, $ticketType, 2);

        $this->orders()->markAsPaid($order);
        $this->orders()->markAsPaid($order);

        self::assertSame(2, $this->countTicketsOf($order));
    }

    public function testUneCommandeAnnuleeNePeutPlusEtrePayee(): void
    {
        $buyer = $this->data->user();
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $order = $this->placeOrder($buyer, $event, $ticketType);
        $this->orders()->cancel($order, $buyer);

        $this->assertOrderError(409, fn () => $this->orders()->markAsPaid($order));

        self::assertSame(0, $this->countTicketsOf($order));
    }

    // ------------------------------------------------------------ expiration

    public function testLExpirationNeTouchQueLesCommandesEnAttenteEchues(): void
    {
        $event = $this->data->event();
        $payant = $this->data->ticketType($event, 'Entrée générale', 20000);
        $gratuit = $this->data->ticketType($event, 'Zone Presse', 0);

        $enAttente = $this->placeOrder($this->data->user(), $event, $payant, 2);
        $payee = $this->placeOrder($this->data->user(), $event, $gratuit, 1);

        $expired = $this->orders()->expirePending(new \DateTimeImmutable('+1 hour'));

        self::assertSame(1, $expired);
        self::assertSame('expired', $this->orderStatus($enAttente));
        self::assertSame('paid', $this->orderStatus($payee));
        self::assertSame(0, $this->reserved($payant), 'Les places de la commande expirée sont rendues.');
        self::assertSame(1, $this->reserved($gratuit), 'Les places vendues restent prises.');
    }

    public function testUneCommandeEncoreDansSonDelaiNExpirePas(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $order = $this->placeOrder($this->data->user(), $event, $ticketType, 2);

        $expired = $this->orders()->expirePending(new \DateTimeImmutable('+5 minutes'));

        self::assertSame(0, $expired);
        self::assertSame('pending', $this->orderStatus($order));
        self::assertSame(2, $this->reserved($ticketType));
    }

    public function testRelancerLExpirationNeRendPasLesPlacesDeuxFois(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $this->placeOrder($this->data->user(), $event, $ticketType, 2);
        $this->placeOrder($this->data->user(), $event, $ticketType, 1);

        self::assertSame(2, $this->orders()->expirePending(new \DateTimeImmutable('+1 hour')));
        self::assertSame(0, $this->orders()->expirePending(new \DateTimeImmutable('+2 hours')));
        self::assertSame(0, $this->reserved($ticketType));
    }
}
