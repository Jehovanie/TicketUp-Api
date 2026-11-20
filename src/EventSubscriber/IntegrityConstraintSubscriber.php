<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Traduit un refus de clé étrangère de PostgreSQL en réponse `409` lisible.
 *
 * La base protège les liens entre les données : un lieu ou une organisation qui
 * porte des événements, un événement ou un tarif qui a des commandes ne peuvent
 * pas être supprimés. Sans ce subscriber, ce refus remonterait en erreur 500,
 * avec le SQL dans la trace.
 *
 * Deux codes sont concernés :
 * - `23503` (clé étrangère `NO ACTION`), que DBAL convertit en
 *   `ForeignKeyConstraintViolationException` ;
 * - `23001` (`ON DELETE RESTRICT`, utilisé par la commande de billets), que DBAL
 *   ne convertit **pas** — d'où le test sur le SQLSTATE plutôt que sur la classe.
 *
 * Le message reste générique : il ne nomme ni table ni contrainte. Le détail
 * technique est journalisé pour le diagnostic.
 */
final class IntegrityConstraintSubscriber implements EventSubscriberInterface
{
    private const SQLSTATES = ['23503', '23001'];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $violation = $this->findViolation($event->getThrowable());

        if ($violation === null) {
            return;
        }

        $this->logger?->warning('Opération refusée par une contrainte de clé étrangère.', [
            'sqlstate' => $violation->getSQLState(),
            'exception' => $violation,
        ]);

        $event->setResponse(new JsonResponse([
            'message' => 'Opération impossible : cet élément est encore lié à d’autres données '
                . '(par exemple un lieu ou une organisation qui a des événements, '
                . 'un événement ou un tarif qui a des commandes).',
            'status' => 409,
            'data' => null,
        ], 409));
    }

    /** Cherche la violation dans la chaîne des exceptions (elle peut être enveloppée). */
    private function findViolation(\Throwable $throwable): ?DriverException
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DriverException && in_array($current->getSQLState(), self::SQLSTATES, true)) {
                return $current;
            }
        }

        return null;
    }
}
