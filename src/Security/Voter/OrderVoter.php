<?php

namespace App\Security\Voter;

use App\Entity\TicketOrder;
use App\Entity\User;
use App\Enum\OrganizerRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui peut consulter une commande :
 * - l'acheteur ;
 * - tout membre de l'organisation qui porte l'événement (gestion des ventes) ;
 * - le super administrateur.
 *
 * L'annulation n'est pas ici : seul l'acheteur annule, règle portée par
 * `OrderService::cancel()`.
 */
final class OrderVoter extends Voter
{
    public const VIEW = 'ORDER_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof TicketOrder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof TicketOrder) {
            return false;
        }

        if ($subject->getUser()?->getId() === $user->getId()) {
            return true;
        }

        $organizer = $subject->getEvent()?->getOrganizer();

        // `hasRoleIn()` laisse passer le super administrateur.
        return $organizer !== null && $user->hasRoleIn($organizer, OrganizerRole::MEMBER);
    }
}
