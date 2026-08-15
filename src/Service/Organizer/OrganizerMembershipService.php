<?php

namespace App\Service\Organizer;

use App\Entity\Organizer;
use App\Entity\OrganizerMembership;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Exception\MembershipException;
use App\Repository\OrganizerMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des appartenances utilisateur ↔ organisation.
 *
 * Toutes les écritures passent par ici : les invariants du modèle (une
 * organisation garde toujours un responsable, un utilisateur n'a qu'un rôle par
 * organisation) sont garantis en un seul endroit, et non dispersés dans les
 * contrôleurs.
 */
final class OrganizerMembershipService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizerMembershipRepository $memberships,
    ) {}

    /**
     * Attribue une organisation à un utilisateur, ou met à jour son rôle s'il en
     * fait déjà partie. Opération idempotente : la rejouer ne crée pas de doublon.
     */
    public function assign(
        User $user,
        Organizer $organizer,
        OrganizerRole $role = OrganizerRole::MEMBER,
        bool $flush = true
    ): OrganizerMembership {
        $membership = $this->memberships->findOneByUserAndOrganizer($user, $organizer);

        if ($membership !== null) {
            return $this->changeRole($user, $organizer, $role, $flush);
        }

        $membership = (new OrganizerMembership())
            ->setUser($user)
            ->setOrganizer($organizer)
            ->setRole($role);

        // On synchronise les deux côtés : les entités déjà chargées restent justes.
        $user->addMembership($membership);
        $organizer->addMembership($membership);

        $this->em->persist($membership);

        if ($flush) {
            $this->em->flush();
        }

        return $membership;
    }

    /**
     * Change le rôle d'un membre.
     *
     * Rétrograder le dernier responsable est refusé : l'organisation deviendrait
     * orpheline, sans personne pour redistribuer les droits.
     */
    public function changeRole(
        User $user,
        Organizer $organizer,
        OrganizerRole $role,
        bool $flush = true
    ): OrganizerMembership {
        $membership = $this->requireMembership($user, $organizer);

        if ($membership->getRole() === $role) {
            return $membership;
        }

        if ($membership->isOwner() && $role !== OrganizerRole::OWNER) {
            $this->assertNotLastOwner($organizer);
        }

        $membership->setRole($role);

        if ($flush) {
            $this->em->flush();
        }

        return $membership;
    }

    /**
     * Retire un utilisateur d'une organisation (et donc son rôle).
     */
    public function revoke(User $user, Organizer $organizer, bool $flush = true): void
    {
        $membership = $this->requireMembership($user, $organizer);

        if ($membership->isOwner()) {
            $this->assertNotLastOwner($organizer);
        }

        $user->removeMembership($membership);
        $organizer->removeMembership($membership);

        $this->em->remove($membership);

        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * Change le responsable d'une organisation.
     *
     * Le nouveau responsable est promu (et rattaché s'il ne l'était pas encore),
     * les responsables précédents sont rétrogradés — par défaut en administrateur,
     * pour ne pas leur retirer d'un coup tout accès à une organisation qu'ils ont
     * dirigée. Passer `$demoteTo = null` les laisse co-responsables.
     */
    public function transferOwnership(
        Organizer $organizer,
        User $newOwner,
        ?OrganizerRole $demoteTo = OrganizerRole::ADMIN
    ): OrganizerMembership {
        $previousOwners = $this->memberships->findByOrganizerAndRole($organizer, OrganizerRole::OWNER);

        // Promotion d'abord : l'organisation n'est jamais sans responsable, même
        // si l'opération échouait en cours de route.
        $newMembership = $this->assign($newOwner, $organizer, OrganizerRole::OWNER, false);

        if ($demoteTo !== null) {
            foreach ($previousOwners as $previous) {
                if ($previous->getUser()?->getId() !== $newOwner->getId()) {
                    $previous->setRole($demoteTo);
                }
            }
        }

        $this->em->flush();

        return $newMembership;
    }

    /**
     * Appartenances d'un utilisateur : ses organisations et son rôle dans chacune.
     *
     * @return OrganizerMembership[]
     */
    public function membershipsOf(User $user, ?int $limit = null, int $offset = 0): array
    {
        return $this->memberships->findByUser($user, $limit, $offset);
    }

    /** Nombre d'organisations auxquelles l'utilisateur appartient. */
    public function countMembershipsOf(User $user): int
    {
        return $this->memberships->countByUser($user);
    }

    /**
     * Membres d'une organisation, responsables en tête.
     *
     * @return OrganizerMembership[]
     */
    public function membersOf(Organizer $organizer): array
    {
        return $this->memberships->findByOrganizer($organizer);
    }

    public function membershipFor(User $user, Organizer $organizer): ?OrganizerMembership
    {
        return $this->memberships->findOneByUserAndOrganizer($user, $organizer);
    }

    private function requireMembership(User $user, Organizer $organizer): OrganizerMembership
    {
        $membership = $this->memberships->findOneByUserAndOrganizer($user, $organizer);

        if ($membership === null) {
            throw MembershipException::pasMembre(
                (string) $user->getEmail(),
                (string) $organizer->getName()
            );
        }

        return $membership;
    }

    private function assertNotLastOwner(Organizer $organizer): void
    {
        if ($this->memberships->countByOrganizerAndRole($organizer, OrganizerRole::OWNER) <= 1) {
            throw MembershipException::dernierResponsable((string) $organizer->getName());
        }
    }
}
