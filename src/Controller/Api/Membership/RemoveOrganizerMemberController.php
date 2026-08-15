<?php

namespace App\Controller\Api\Membership;

use App\Enum\OrganizerRole;
use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipRequestResolver;
use App\Service\Organizer\OrganizerMembershipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Retire un utilisateur d'une organisation (et donc son rôle).
 *
 * Le service refuse de retirer le dernier responsable : l'organisation
 * deviendrait ingérable.
 */
#[AsController]
#[Route(
    '/api/organizers/{organizerId}/members/{userId}',
    name: 'api_organizer_member_remove',
    methods: ['DELETE'],
    requirements: ['organizerId' => '\d+', 'userId' => '\d+']
)]
final class RemoveOrganizerMemberController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly OrganizerMembershipService $membershipService,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, int $userId): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::MANAGE_MEMBERS, $organizer);

        $user = $this->resolver->userById($userId);

        if ($user->getRoleIn($organizer) === OrganizerRole::OWNER) {
            $this->denyAccessUnlessGranted(OrganizerVoter::TRANSFER_OWNERSHIP, $organizer);
        }

        $this->membershipService->revoke($user, $organizer);

        return $this->json([
            'message' => sprintf('%s a été retiré de %s.', $user->getEmail(), $organizer->getName()),
            'status' => 200,
            'data' => null,
        ], 200);
    }
}
