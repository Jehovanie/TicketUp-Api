<?php

namespace App\Service\Event;

use App\Entity\Event;
use App\Exception\EventException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Passage d'un événement de brouillon à publié (`event.status = true`).
 *
 * Règles, vérifiées dans cet ordre :
 * 1. **idempotence** : un événement déjà publié est renvoyé tel quel, sans
 *    revalidation — republier ne peut jamais échouer, même si l'événement a
 *    entre-temps perdu un billet ou est terminé ;
 * 2. au moins **un type de billet** : un événement publié sans tarif ne peut
 *    rien vendre, et la commande de billets serait impossible ;
 * 3. une **date de fin future** : publier un événement déjà terminé n'a pas de
 *    sens (la vente est d'ailleurs refusée dès qu'il est passé).
 *
 * Violation → `EventException` `409` : le conflit vient de l'état de
 * l'événement, pas de la forme de la requête.
 */
final class EventPublisher
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return bool `true` si l'événement vient d'être publié, `false` s'il l'était déjà */
    public function publish(Event $event, ?\DateTimeImmutable $now = null): bool
    {
        if ($event->isStatus() === true) {
            return false;
        }

        $this->assertPublishable($event, $event->getEndAt(), $now);

        $event->setStatus(true);
        // `Event` n'a pas de `PreUpdate` (Q-29).
        $event->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return true;
    }

    /**
     * Vérifie les règles 2 et 3 sans rien modifier. `$endAt` est la date de fin
     * **telle qu'elle sera** : une modification (`PATCH`) qui change la date et
     * publie dans le même appel est jugée sur la nouvelle valeur.
     */
    public function assertPublishable(Event $event, ?\DateTimeImmutable $endAt, ?\DateTimeImmutable $now = null): void
    {
        if ($event->getTicketType()->isEmpty()) {
            throw EventException::publicationSansBillet();
        }

        if ($endAt === null || $endAt <= ($now ?? new \DateTimeImmutable())) {
            throw EventException::publicationTerminee();
        }
    }
}
