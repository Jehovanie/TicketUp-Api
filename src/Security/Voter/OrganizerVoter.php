<?php

namespace App\Security\Voter;

use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur une organisation, déduits du rôle de l'utilisateur **dans cette
 * organisation** — et non de ses rôles globaux.
 *
 * Le fondateur de la plateforme (`ROLE_SUPER_ADMIN`) passe partout ; pour tous
 * les autres, appartenir à « Tech Madagascar » ne donne aucun droit sur
 * « Madagascar Events ».
 */
final class OrganizerVoter extends Voter
{
    /** Consulter l'organisation et ses membres. */
    public const VIEW = 'ORGANIZER_VIEW';

    /** Modifier les informations de l'organisation. */
    public const EDIT = 'ORGANIZER_EDIT';

    /** Ajouter, retirer un membre ou changer son rôle. */
    public const MANAGE_MEMBERS = 'ORGANIZER_MANAGE_MEMBERS';

    /** Désigner un nouveau responsable. */
    public const TRANSFER_OWNERSHIP = 'ORGANIZER_TRANSFER_OWNERSHIP';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Organizer
            && in_array($attribute, [self::VIEW, self::EDIT, self::MANAGE_MEMBERS, self::TRANSFER_OWNERSHIP], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof Organizer) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $user->hasRoleIn($subject, OrganizerRole::MEMBER),
            self::EDIT, self::MANAGE_MEMBERS => $user->hasRoleIn($subject, OrganizerRole::ADMIN),
            self::TRANSFER_OWNERSHIP => $user->hasRoleIn($subject, OrganizerRole::OWNER),
            default => false,
        };
    }
}
