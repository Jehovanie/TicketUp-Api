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
 * Rattache un utilisateur à une organisation avec un rôle.
 *
 * Corps attendu : `{ "userId": 3 }` ou `{ "email": "…" }`, plus un `role`
 * optionnel (`owner`, `admin`, `member` — `member` par défaut).
 * Rejouer l'appel met simplement le rôle à jour.
 */
#[AsController]
#[Route('/api/organizers/{organizerId}/members', name: 'api_organizer_member_add', methods: ['POST'], requirements: ['organizerId' => '\d+'])]
final class AddOrganizerMemberController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::MANAGE_MEMBERS, $organizer);

        $payload = $this->resolver->payload($request);
        $user = $this->resolver->userFromPayload($payload);
        $role = $this->resolver->role($payload, OrganizerRole::MEMBER);

        // Seul un responsable peut en désigner un autre.
        if ($role === OrganizerRole::OWNER) {
            $this->denyAccessUnlessGranted(OrganizerVoter::TRANSFER_OWNERSHIP, $organizer);
        }

        $membership = $this->membershipService->assign($user, $organizer, $role);

        return $this->json([
            'message' => sprintf(
                '%s a été rattaché à %s en tant que %s.',
                $user->getEmail(),
                $organizer->getName(),
                $membership->getRole()->label()
            ),
            'status' => 201,
            'data' => $this->presenter->member($membership),
        ], 201);
    }
}
