<?php

namespace App\Service\Organizer;

use App\Entity\OrganizerMembership;

/**
 * Mise en forme des appartenances pour les réponses JSON.
 *
 * Les entités `User` et `Organizer` n'ont pas de groupes de sérialisation
 * communs ; plutôt que d'en ajouter partout, on compose ici les deux vues dont
 * l'API a besoin — côté organisation (« qui en fait partie ») et côté
 * utilisateur (« à quoi j'appartiens »).
 */
final class MembershipPresenter
{
    /** Vue « membre d'une organisation ». */
    public function member(OrganizerMembership $membership): array
    {
        $user = $membership->getUser();

        return [
            'userId' => $user?->getId(),
            'email' => $user?->getEmail(),
            'firstname' => $user?->getFirstname(),
            'lastname' => $user?->getLastname(),
            'phone' => $user?->getPhone(),
            'role' => $membership->getRole()->value,
            'roleLabel' => $membership->getRole()->label(),
            'isOwner' => $membership->isOwner(),
            'joinedAt' => $membership->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Vue « organisation d'un utilisateur ». */
    public function organization(OrganizerMembership $membership): array
    {
        $organizer = $membership->getOrganizer();

        return [
            'id' => $organizer?->getId(),
            'name' => $organizer?->getName(),
            'email' => $organizer?->getEmail(),
            'phone' => $organizer?->getPhone(),
            'website' => $organizer?->getWebsite(),
            'role' => $membership->getRole()->value,
            'roleLabel' => $membership->getRole()->label(),
            'isOwner' => $membership->isOwner(),
            'joinedAt' => $membership->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Vue réduite d'une organisation : juste de quoi l'afficher dans une liste
     * ou un sélecteur, avec le rôle qui décide de ce que l'interface propose.
     *
     * Les coordonnées (`email`, `phone`, `website`) et la date d'entrée sont
     * volontairement absentes : elles n'ont d'utilité que sur la fiche complète,
     * servie par `/api/user/me/organizations` ou `/api/organizer/{id}`.
     */
    public function organizationSummary(OrganizerMembership $membership): array
    {
        $organizer = $membership->getOrganizer();

        return [
            'id' => $organizer?->getId(),
            'name' => $organizer?->getName(),
            'role' => $membership->getRole()->value,
            'roleLabel' => $membership->getRole()->label(),
            'isOwner' => $membership->isOwner(),
        ];
    }

    /**
     * @param OrganizerMembership[] $memberships
     */
    public function members(array $memberships): array
    {
        return array_map(fn (OrganizerMembership $m) => $this->member($m), $memberships);
    }

    /**
     * @param OrganizerMembership[] $memberships
     */
    public function organizations(array $memberships): array
    {
        return array_map(fn (OrganizerMembership $m) => $this->organization($m), $memberships);
    }

    /**
     * @param OrganizerMembership[] $memberships
     */
    public function organizationSummaries(array $memberships): array
    {
        return array_map(fn (OrganizerMembership $m) => $this->organizationSummary($m), $memberships);
    }
}
