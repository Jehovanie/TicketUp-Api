<?php

namespace App\Enum;

use App\Exception\OrganizerException;

/**
 * Opérateur de mobile money pris en charge pour encaisser les billets.
 *
 * Les valeurs sont celles prévues pour `payment.provider` (voir
 * `docs/database/design-orders.md`) : un même vocabulaire de bout en bout.
 *
 * Chaque opérateur est rattaché à des préfixes de numéro (`03X`). Les contrôler
 * à la saisie évite d'enregistrer un numéro Airtel sous « MVola » : une faute
 * qui n'apparaîtrait qu'au premier paiement, quand l'argent part au mauvais endroit.
 */
enum MobileMoneyProvider: string
{
    case MVOLA = 'mvola';
    case ORANGE_MONEY = 'orange_money';
    case AIRTEL_MONEY = 'airtel_money';

    public function label(): string
    {
        return match ($this) {
            self::MVOLA => 'MVola',
            self::ORANGE_MONEY => 'Orange Money',
            self::AIRTEL_MONEY => 'Airtel Money',
        };
    }

    /**
     * Indicatifs à deux chiffres qui suivent le `0` national (ex. `34` pour `034…`).
     *
     * @return string[]
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::MVOLA => ['34', '38'],
            self::ORANGE_MONEY => ['32', '37'],
            self::AIRTEL_MONEY => ['33'],
        };
    }

    /** @return self[] */
    public static function all(): array
    {
        return [self::MVOLA, self::ORANGE_MONEY, self::AIRTEL_MONEY];
    }

    /** Conversion tolérante (« MVola », « orange money », « Airtel-Money »…). */
    public static function fromInput(?string $value): self
    {
        $normalized = str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $provider = self::tryFrom($normalized);

        if ($provider === null) {
            throw OrganizerException::operateurInconnu((string) $value);
        }

        return $provider;
    }

    /**
     * Normalise un numéro malgache au format international `+261XXXXXXXXX`
     * et vérifie qu'il appartient bien à cet opérateur.
     *
     * Accepte `034 12 345 67`, `+261 34 12 345 67`, `0034…`, `261…`, avec
     * espaces, points, tirets ou parenthèses.
     */
    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/[\s.\-()]/', '', $raw) ?? '';

        $national = match (true) {
            str_starts_with($digits, '+261') => '0' . substr($digits, 4),
            str_starts_with($digits, '00261') => '0' . substr($digits, 5),
            str_starts_with($digits, '261') => '0' . substr($digits, 3),
            default => $digits,
        };

        if (preg_match('/^0\d{9}$/', $national) !== 1) {
            throw OrganizerException::numeroInvalide($raw);
        }

        if (!in_array(substr($national, 1, 2), $this->prefixes(), true)) {
            throw OrganizerException::numeroIncompatible($raw, $this);
        }

        return '+261' . substr($national, 1);
    }
}
