<?php

namespace App\Exception;

/** Erreurs de saisie des filtres de liste d'événements. */
final class EventException extends BusinessException
{
    public static function valeurInvalide(string $parametre, array $acceptes): self
    {
        return new self(
            sprintf('Le paramètre « %s » doit valoir : %s.', $parametre, implode(', ', $acceptes)),
            400
        );
    }

    public static function identifiantInvalide(string $parametre): self
    {
        return new self(sprintf('Le paramètre « %s » doit être un identifiant (entier positif).', $parametre), 400);
    }

    public static function dateInvalide(string $parametre): self
    {
        return new self(sprintf('Le paramètre « %s » doit être une date au format AAAA-MM-JJ.', $parametre), 400);
    }

    public static function periodeInversee(): self
    {
        return new self('« startDate » doit précéder ou égaler « endDate ».', 400);
    }

    public static function texteInvalide(string $parametre): self
    {
        return new self(sprintf('Le paramètre « %s » doit être une chaîne de caractères.', $parametre), 400);
    }

    public static function brouillonsReserves(): self
    {
        return new self('Les brouillons ne sont visibles que du super administrateur : filtrez sur « status=published ».', 403);
    }

    public static function organisationHorsPerimetre(): self
    {
        return new self('Cette organisation ne fait pas partie de vos organisations.', 403);
    }
}
