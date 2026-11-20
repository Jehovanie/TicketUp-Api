<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Garde `refresh_tokens` cohérent avec `user`, faute de clé étrangère
 * (le bundle Gesdinet lie les jetons par email).
 *
 * - compte supprimé → ses jetons sont supprimés ;
 * - email modifié → ses jetons suivent le nouvel email (les sessions restent ouvertes).
 *
 * Les deux requêtes s'exécutent dans la transaction du `flush` : en cas
 * d'échec, tout est annulé ensemble.
 *
 * ⚠️ Ne couvre que les opérations faites par l'ORM. Une suppression ou une
 * modification en SQL direct doit traiter `refresh_tokens` elle-même.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: User::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: User::class)]
final class RefreshTokenUserListener
{
    public function preUpdate(User $user, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('email')) {
            return;
        }

        $args->getObjectManager()->getConnection()->executeStatement(
            'UPDATE refresh_tokens SET username = :new WHERE username = :old',
            ['new' => $args->getNewValue('email'), 'old' => $args->getOldValue('email')]
        );
    }

    public function postRemove(User $user, PostRemoveEventArgs $args): void
    {
        $args->getObjectManager()->getConnection()->executeStatement(
            'DELETE FROM refresh_tokens WHERE username = :email',
            ['email' => $user->getEmail()]
        );
    }
}
