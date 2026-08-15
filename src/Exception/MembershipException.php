<?php

namespace App\Exception;

use App\Enum\OrganizerRole;

/**
 * Erreurs métier de la gestion des appartenances.
 *
 * Le code HTTP et la mise en forme de la réponse sont portés par
 * `BusinessException` ; il ne reste ici que les cas propres au domaine.
 */
class MembershipException extends BusinessException
{
    public static function roleInconnu(string $value): self
    {
        $roles = implode(', ', array_map(static fn (OrganizerRole $role) => $role->value, OrganizerRole::all()));

        return new self(
            sprintf('Rôle « %s » inconnu. Valeurs acceptées : %s.', $value, $roles),
            400
        );
    }

    public static function utilisateurIntrouvable(string $reference): self
    {
        return new self(sprintf('Utilisateur introuvable (%s).', $reference), 404);
    }

    public static function organisationIntrouvable(int $organizerId): self
    {
        return new self(sprintf('Organisation introuvable (id: %d).', $organizerId), 404);
    }

    public static function pasMembre(string $email, string $organizerName): self
    {
        return new self(
            sprintf('%s ne fait pas partie de %s.', $email, $organizerName),
            404
        );
    }

    /**
     * Une organisation sans responsable serait ingérable : plus personne ne
     * pourrait y attribuer de droits.
     */
    public static function dernierResponsable(string $organizerName): self
    {
        return new self(
            sprintf(
                'Impossible : %s doit conserver au moins un responsable. Désignez un nouveau responsable avant de retirer celui-ci.',
                $organizerName
            ),
            409
        );
    }

    public static function accesRefuse(string $organizerName): self
    {
        return new self(
            sprintf('Vous n’avez pas les droits nécessaires sur %s.', $organizerName),
            403
        );
    }
}
