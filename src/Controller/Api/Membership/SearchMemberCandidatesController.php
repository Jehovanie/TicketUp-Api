<?php

namespace App\Controller\Api\Membership;

use App\Exception\OrganizerException;
use App\Repository\UserRepository;
use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipRequestResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recherche d'utilisateurs à inviter dans une organisation, pour alimenter un
 * champ de saisie avant `POST /api/organizers/{id}/members`.
 *
 * Droit requis : `ORGANIZER_MANAGE_MEMBERS` (administrateur ou plus) — c'est le
 * même droit que l'invitation elle-même.
 *
 * Ce point d'accès expose un annuaire d'utilisateurs ; il est donc borné :
 * - terme d'au moins {@see self::MIN_LENGTH} caractères (`q`), pas de liste complète ;
 * - {@see self::LIMIT} résultats au plus, sans pagination ;
 * - champs minimaux (`id`, `email`, `firstname`, `lastname`) : ni téléphone ni rôles ;
 * - les membres actuels de l'organisation sont exclus.
 */
#[AsController]
#[Route('/api/organizers/{organizerId}/member-candidates', name: 'api_organizer_member_candidates', methods: ['GET'], requirements: ['organizerId' => '\d+'])]
final class SearchMemberCandidatesController extends AbstractController
{
    public const MIN_LENGTH = 3;
    public const LIMIT = 10;

    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly UserRepository $users,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::MANAGE_MEMBERS, $organizer);

        $q = $request->query->all()['q'] ?? null;
        $term = is_string($q) ? trim($q) : '';

        if (mb_strlen($term) < self::MIN_LENGTH) {
            throw OrganizerException::parametreInvalide(
                sprintf('Le paramètre « q » doit contenir au moins %d caractères.', self::MIN_LENGTH)
            );
        }

        $items = array_map(static fn ($u) => [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'firstname' => $u->getFirstname(),
            'lastname' => $u->getLastname(),
        ], $this->users->searchInvitable($organizer, $term, self::LIMIT));

        return $this->json([
            'message' => 'Utilisateurs trouvés',
            'status' => 200,
            'data' => [
                'itemsTotal' => count($items),
                'items' => $items,
            ],
        ], 200);
    }
}
