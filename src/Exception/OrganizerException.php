<?php

namespace App\Exception;

use App\Enum\MobileMoneyProvider;

/**
 * Erreurs de saisie à la création d'une organisation.
 *
 * L'entité `Organizer` ne porte aucune contrainte de validation et la colonne
 * `email` n'est pas unique en base : sans ces vérifications, un nom vide ou une
 * adresse invalide passerait jusqu'à l'INSERT — au mieux une organisation
 * inutilisable, au pire une erreur SQL rendue en 500.
 */
final class OrganizerException extends BusinessException
{
    public static function champRequis(string $champ): self
    {
        return new self(sprintf('Le champ « %s » est obligatoire.', $champ), 400);
    }

    public static function champTropLong(string $champ, int $maximum): self
    {
        return new self(
            sprintf('Le champ « %s » ne doit pas dépasser %d caractères.', $champ, $maximum),
            400
        );
    }

    public static function emailInvalide(string $email): self
    {
        return new self(sprintf('« %s » n’est pas une adresse email valide.', $email), 400);
    }

    public static function siteInvalide(string $website): self
    {
        return new self(
            sprintf('« %s » n’est pas une adresse web valide (attendu : https://exemple.mg).', $website),
            400
        );
    }

    public static function organisateurRequis(): self
    {
        return new self(
            'Le champ « organizer.id » est obligatoire : créez d’abord l’organisation via POST /api/organizers.',
            400
        );
    }

    public static function aucuneModification(): self
    {
        return new self(
            'Aucun champ à modifier : fournissez au moins un champ parmi name, email, phone, website, paymentMethods.',
            400
        );
    }

    public static function filtreInvalide(string $parametre, array $acceptes): self
    {
        return new self(
            sprintf('Le paramètre « %s » doit valoir : %s.', $parametre, implode(', ', $acceptes)),
            400
        );
    }

    public static function parametreInvalide(string $message): self
    {
        return new self($message, 400);
    }

    // ---------------------------------------------------- Moyens de paiement

    public static function paiementRequis(): self
    {
        return new self(
            'Au moins un compte mobile money (MVola, Orange Money ou Airtel Money) est obligatoire.',
            400
        );
    }

    public static function paiementInvalide(): self
    {
        return new self(
            'Le champ « paymentMethods » doit être une liste d’objets '
            . '{ "provider", "phoneNumber", "accountName" }.',
            400
        );
    }

    public static function operateurInconnu(string $valeur): self
    {
        return new self(
            sprintf('« %s » n’est pas un opérateur pris en charge (attendu : mvola, orange_money, airtel_money).', $valeur),
            400
        );
    }

    public static function operateurEnDouble(MobileMoneyProvider $provider): self
    {
        return new self(
            sprintf('Un seul compte %s est autorisé par organisation.', $provider->label()),
            400
        );
    }

    public static function numeroInvalide(string $numero): self
    {
        return new self(
            sprintf('« %s » n’est pas un numéro malgache valide (attendu : 0XX XX XXX XX ou +261 XX XX XXX XX).', $numero),
            400
        );
    }

    public static function numeroIncompatible(string $numero, MobileMoneyProvider $provider): self
    {
        return new self(
            sprintf(
                '« %s » n’est pas un numéro %s (préfixes attendus : %s).',
                $numero,
                $provider->label(),
                implode(', ', array_map(static fn (string $p) => '0' . $p, $provider->prefixes()))
            ),
            400
        );
    }
}
