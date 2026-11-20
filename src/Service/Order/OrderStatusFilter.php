<?php

namespace App\Service\Order;

use App\Enum\OrderStatus;
use App\Exception\OrderException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lit le filtre `?status=` des listes de commandes.
 *
 * Une valeur (`status=pending`) ou plusieurs séparées par des virgules
 * (`status=cancelled,expired`). Absent = tous les statuts. Toute valeur
 * inconnue, vide ou mal formée → 400 avec la liste des valeurs acceptées.
 */
final class OrderStatusFilter
{
    /** @return OrderStatus[] vide si le paramètre est absent */
    public static function fromRequest(Request $request): array
    {
        $query = $request->query->all();

        if (!array_key_exists('status', $query)) {
            return [];
        }

        $raw = $query['status'];
        if (!is_string($raw) || trim($raw) === '') {
            throw self::invalid();
        }

        $statuses = [];
        foreach (explode(',', $raw) as $value) {
            $statuses[] = OrderStatus::tryFrom(trim($value)) ?? throw self::invalid();
        }

        return array_values(array_unique($statuses, SORT_REGULAR));
    }

    private static function invalid(): OrderException
    {
        return OrderException::statutInvalide(array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases()));
    }
}
