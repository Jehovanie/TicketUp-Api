<?php

namespace App\Service\Organizer;

use App\Entity\Organizer;
use App\Entity\OrganizerMembership;
use App\Entity\OrganizerPaymentMethod;
use App\Enum\MobileMoneyProvider;

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
        return $this->summaryFields($membership->getOrganizer(), $membership);
    }

    /**
     * Fiche complète d'une organisation pour un de ses membres : la vue
     * `organization()` plus les dates, et le rôle **de l'appelant**.
     *
     * Un `ROLE_SUPER_ADMIN` qui n'est pas membre voit la fiche sans rôle
     * (`role`, `roleLabel`, `joinedAt` à `null`, `isOwner` à `false`).
     */
    public function detail(Organizer $organizer, ?OrganizerMembership $membership): array
    {
        return $this->summaryFields($organizer, $membership) + [
            'createdAt' => $organizer->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $organizer->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function summaryFields(?Organizer $organizer, ?OrganizerMembership $membership): array
    {
        return [
            'id' => $organizer?->getId(),
            'name' => $organizer?->getName(),
            'email' => $organizer?->getEmail(),
            'phone' => $organizer?->getPhone(),
            'website' => $organizer?->getWebsite(),
            'paymentMethods' => $this->paymentMethods($organizer),
            'role' => $membership?->getRole()->value,
            'roleLabel' => $membership?->getRole()->label(),
            'isOwner' => $membership?->isOwner() ?? false,
            'joinedAt' => $membership?->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Comptes mobile money de l'organisation, réservés à ses membres : cette
     * vue n'est servie qu'aux personnes rattachées à l'organisation.
     */
    private function paymentMethods(?Organizer $organizer): array
    {
        if ($organizer === null) {
            return [];
        }

        // Ordre stable (celui de l'enum), quel que soit l'ordre d'insertion.
        $order = array_flip(array_map(static fn (MobileMoneyProvider $p) => $p->value, MobileMoneyProvider::all()));
        $methods = $organizer->getPaymentMethods()->toArray();
        usort($methods, static fn (OrganizerPaymentMethod $a, OrganizerPaymentMethod $b) => $order[$a->getProvider()->value] <=> $order[$b->getProvider()->value]);

        return array_map(static fn (OrganizerPaymentMethod $m) => [
            'provider' => $m->getProvider()?->value,
            'providerLabel' => $m->getProvider()?->label(),
            'phoneNumber' => $m->getPhoneNumber(),
            'accountName' => $m->getAccountName(),
        ], $methods);
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
