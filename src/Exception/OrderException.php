<?php

namespace App\Exception;

use App\Service\Order\OrderService;

/**
 * Erreurs métier de la commande de billets.
 *
 * Confidentialité : une commande ou un événement que l'appelant n'a pas le droit
 * de voir répond 404, comme s'il n'existait pas — un 403 révélerait qu'une
 * référence de commande ou un brouillon existe.
 */
final class OrderException extends BusinessException
{
    // ------------------------------------------------------------- saisie

    public static function corpsInvalide(): self
    {
        return new self('Le corps de la requête doit être un objet JSON valide.', 400);
    }

    public static function champInvalide(string $champ, string $attendu): self
    {
        return new self(sprintf('Le champ « %s » est obligatoire et doit être %s.', $champ, $attendu), 400);
    }

    public static function cleIdempotenceInvalide(int $maximum): self
    {
        return new self(sprintf('L’en-tête Idempotency-Key ne doit pas dépasser %d caractères.', $maximum), 400);
    }

    public static function aucuneLigne(): self
    {
        return new self('La commande doit contenir au moins un tarif.', 400);
    }

    public static function quantiteInvalide(int $ticketTypeId): self
    {
        return new self(sprintf('La quantité du tarif %d doit être supérieure à zéro.', $ticketTypeId), 400);
    }

    public static function tarifEnDouble(int $ticketTypeId): self
    {
        return new self(sprintf('Le tarif %d apparaît plusieurs fois : regroupez les quantités sur une seule ligne.', $ticketTypeId), 400);
    }

    public static function tropDeBillets(int $demandes): self
    {
        return new self(sprintf(
            'Une commande est limitée à %d billets (%d demandés).',
            OrderService::MAX_TICKETS_PER_ORDER,
            $demandes
        ), 400);
    }

    public static function tarifInconnu(int $ticketTypeId): self
    {
        return new self(sprintf('Le tarif %d n’existe pas pour cet événement.', $ticketTypeId), 400);
    }

    // ------------------------------------------------------------ existence

    public static function evenementIntrouvable(int $eventId): self
    {
        return new self(sprintf('Événement introuvable (id: %d).', $eventId), 404);
    }

    public static function commandeIntrouvable(string $reference): self
    {
        return new self(sprintf('Commande introuvable (%s).', $reference), 404);
    }

    public static function accesRefuse(): self
    {
        return new self('Vous n’avez pas accès aux commandes de cet événement.', 403);
    }

    // ------------------------------------------------------------- conflits

    public static function venteTerminee(string $title): self
    {
        return new self(sprintf('La vente est terminée : « %s » est passé.', $title), 409);
    }

    public static function stockInsuffisant(string $ticketTypeName, int $disponible): self
    {
        return new self(sprintf(
            'Plus assez de places pour le tarif « %s » (%d restante%s).',
            $ticketTypeName,
            $disponible,
            $disponible > 1 ? 's' : ''
        ), 409);
    }

    public static function transitionImpossible(string $reference, string $statusLabel): self
    {
        return new self(sprintf('Opération impossible : la commande %s est « %s ».', $reference, mb_strtolower($statusLabel)), 409);
    }
}
