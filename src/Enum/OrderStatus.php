<?php

namespace App\Enum;

/**
 * Statut d'une commande de billets.
 *
 *     pending ──► paid ──► refunded
 *        │          └────► cancelled (événement annulé)
 *        ├──► expired   (délai de paiement dépassé)
 *        └──► cancelled (annulée par l'acheteur)
 *
 * Les valeurs sont stockées en base et contrôlées par une contrainte CHECK :
 * en ajouter une demande une migration.
 */
enum OrderStatus: string
{
    /** Envoyée, places réservées, en attente de paiement jusqu'à `expiresAt`. */
    case PENDING = 'pending';

    /** Payée (ou gratuite) : les billets sont émis. */
    case PAID = 'paid';

    case CANCELLED = 'cancelled';

    /** Non payée à temps : les places ont été rendues. */
    case EXPIRED = 'expired';

    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente de paiement',
            self::PAID => 'Payée',
            self::CANCELLED => 'Annulée',
            self::EXPIRED => 'Expirée',
            self::REFUNDED => 'Remboursée',
        };
    }

    /** Vrai si les places de la commande sont décomptées du quota des tarifs. */
    public function holdsSeats(): bool
    {
        return $this === self::PENDING || $this === self::PAID;
    }

    /** Vrai si plus aucune transition n'est possible. */
    public function isFinal(): bool
    {
        return in_array($this, [self::CANCELLED, self::EXPIRED, self::REFUNDED], true);
    }
}
