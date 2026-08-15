<?php

namespace App\Controller\Api\Membership;

use App\Enum\OrganizerRole;
use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\MembershipRequestResolver;
use App\Service\Organizer\OrganizerMembershipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Change le rôle d'un membre dans une organisation.
 *
 * Corps attendu : `{ "role": "admin" }`.
 */
#[AsController]
#[Route(
    '/api/organizers/{organizerId}/members/{userId}',
    name: 'api_organizer_member_update',
    methods: ['PATCH', 'PUT'],
    requirements: ['organizerId' => '\d+', 'userId' => '\d+']
)]
final class UpdateOrganizerMemberRoleController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, int $userId, Request $request): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::MANAGE_MEMBERS, $organizer);

        $user = $this->resolver->userById($userId);
        $role = $this->resolver->role($this->resolver->payload($request));

        // Promouvoir ou rétrograder un responsable relève du responsable.
        if ($role === OrganizerRole::OWNER || $user->getRoleIn($organizer) === OrganizerRole::OWNER) {
            $this->denyAccessUnlessGranted(OrganizerVoter::TRANSFER_OWNERSHIP, $organizer);
        }

        $membership = $this->membershipService->changeRole($user, $organizer, $role);

        return $this->json([
            'message' => sprintf(
                'Rôle de %s mis à jour : %s.',
                $user->getEmail(),
                $membership->getRole()->label()
            ),
            'status' => 200,
            'data' => $this->presenter->member($membership),
        ], 200);
    }
}
