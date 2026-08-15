<?php

namespace App\EventSubscriber;

use App\Exception\BusinessException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Traduit les erreurs métier en réponses JSON propres.
 *
 * Sans ça, une `BusinessException` remonterait en 500 avec une trace : le
 * client ne saurait pas distinguer « rôle inconnu » de « panne serveur ».
 * Volontairement limité à cette hiérarchie d'exceptions : le reste de la
 * gestion d'erreurs n'est pas modifié.
 */
final class BusinessExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof BusinessException) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'message' => $exception->getMessage(),
            'status' => $exception->getStatusCode(),
            'data' => null,
        ], $exception->getStatusCode()));
    }
}
