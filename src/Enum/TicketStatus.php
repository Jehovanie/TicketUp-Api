<?php

namespace App\Enum;

/** Statut d'un billet émis (une place, un QR code). */
enum TicketStatus: string
{
    case VALID = 'valid';

    /** Scanné à l'entrée. */
    case USED = 'used';

    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Valide',
            self::USED => 'Utilisé',
            self::CANCELLED => 'Annulé',
        };
    }
}
