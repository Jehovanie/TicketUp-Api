<?php

namespace App\Service\Order;

/**
 * Codes aléatoires de la commande de billets.
 *
 * - **Référence de commande** `TU-XXXXXXXX` : lue et dictée au service client,
 *   d'où un alphabet sans caractères ambigus (ni I, O, 0, 1). 32⁸ ≈ 10¹²
 *   combinaisons : impossible à deviner, contrairement à un identifiant
 *   séquentiel.
 * - **Code de billet** : 32 caractères hexadécimaux (128 bits), destiné au QR code.
 */
class OrderReferenceGenerator
{
    private const PREFIX = 'TU-';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 8;

    public function generate(): string
    {
        $reference = self::PREFIX;
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $reference .= self::ALPHABET[random_int(0, $max)];
        }

        return $reference;
    }

    public function ticketCode(): string
    {
        return bin2hex(random_bytes(16));
    }
}
