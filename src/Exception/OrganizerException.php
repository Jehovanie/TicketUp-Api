<?php

namespace App\Exception;

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
}
