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
 * Change le responsable d'une organisation.
 *
 * Corps attendu : `{ "userId": 4 }` ou `{ "email": "…" }`, avec un `demoteTo`
 * optionnel (`admin` par défaut, `null` pour conserver les responsables actuels
 * en co-responsables).
 */
#[AsController]
#[Route('/api/organizers/{organizerId}/owner', name: 'api_organizer_transfer_ownership', methods: ['PUT'], requirements: ['organizerId' => '\d+'])]
final class TransferOrganizerOwnershipController extends AbstractController
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
        $this->denyAccessUnlessGranted(OrganizerVoter::TRANSFER_OWNERSHIP, $organizer);

        $payload = $this->resolver->payload($request);
        $newOwner = $this->resolver->userFromPayload($payload);

        // `demoteTo: null` explicite = on garde les anciens responsables.
        $demoteTo = array_key_exists('demoteTo', $payload) && $payload['demoteTo'] === null
            ? null
            : OrganizerRole::fromInput((string) ($payload['demoteTo'] ?? OrganizerRole::ADMIN->value));

        $membership = $this->membershipService->transferOwnership($organizer, $newOwner, $demoteTo);

        return $this->json([
            'message' => sprintf(
                '%s est désormais responsable de %s.',
                $newOwner->getEmail(),
                $organizer->getName()
            ),
            'status' => 200,
            'data' => [
                'owner' => $this->presenter->member($membership),
                'members' => $this->presenter->members($this->membershipService->membersOf($organizer)),
            ],
        ], 200);
    }
}
