<?php

namespace App\Service\Organizer;

use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Exception\MembershipException;
use App\Repository\OrganizerRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lecture des entrées des endpoints d'appartenance.
 *
 * Les quatre contrôleurs d'écriture ont besoin des mêmes conversions
 * (identifiant → entité, chaîne → rôle) avec les mêmes messages d'erreur ;
 * elles sont regroupées ici pour rester cohérentes.
 */
final class MembershipRequestResolver
{
    public function __construct(
        private readonly OrganizerRepository $organizers,
        private readonly UserRepository $users,
    ) {}

    public function payload(Request $request): array
    {
        $payload = json_decode($request->getContent() ?: '[]', true);

        return is_array($payload) ? $payload : [];
    }

    public function organizer(int $organizerId): Organizer
    {
        $organizer = $this->organizers->find($organizerId);

        if ($organizer === null) {
            throw MembershipException::organisationIntrouvable($organizerId);
        }

        return $organizer;
    }

    public function userById(int $userId): User
    {
        $user = $this->users->find($userId);

        if ($user === null) {
            throw MembershipException::utilisateurIntrouvable('id: ' . $userId);
        }

        return $user;
    }

    /** Accepte `userId` ou `email` : les deux sont pratiques selon l'appelant. */
    public function userFromPayload(array $payload): User
    {
        if (isset($payload['userId']) && $payload['userId'] !== null) {
            return $this->userById((int) $payload['userId']);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($email === '') {
            throw MembershipException::utilisateurIntrouvable('renseignez « userId » ou « email »');
        }

        $user = $this->users->findOneBy(['email' => $email]);

        if ($user === null) {
            throw MembershipException::utilisateurIntrouvable($email);
        }

        return $user;
    }

    public function role(array $payload, ?OrganizerRole $default = null): OrganizerRole
    {
        $value = $payload['role'] ?? null;

        if ($value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw MembershipException::roleInconnu('');
        }

        return OrganizerRole::fromInput((string) $value);
    }
}
