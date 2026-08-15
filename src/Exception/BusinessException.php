<?php

namespace App\Exception;

/**
 * Erreur métier destinée à être rendue telle quelle au client.
 *
 * Chaque cas porte son code HTTP : les contrôleurs n'ont pas à deviner s'il
 * s'agit d'une requête mal formée (400), d'un droit manquant (403) ou d'un
 * conflit d'état (409). `BusinessExceptionSubscriber` les traduit toutes en
 * réponses JSON enveloppées, ce qui évite de répéter cette mise en forme.
 */
abstract class BusinessException extends \DomainException
{
    public function __construct(string $message, private readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
