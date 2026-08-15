<?php

namespace App\Controller\Api\Membership;

use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\MembershipRequestResolver;
use App\Service\Organizer\OrganizerMembershipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Membres d'une organisation, responsables en tête. */
#[AsController]
#[Route('/api/organizers/{organizerId}/members', name: 'api_organizer_members', methods: ['GET'], requirements: ['organizerId' => '\d+'])]
final class GetOrganizerMembersController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::VIEW, $organizer);

        $memberships = $this->membershipService->membersOf($organizer);

        return $this->json([
            'message' => 'Membres de l’organisation récupérés avec succès',
            'status' => 200,
            'data' => [
                'organizer' => [
                    'id' => $organizer->getId(),
                    'name' => $organizer->getName(),
                ],
                'itemsTotal' => count($memberships),
                'items' => $this->presenter->members($memberships),
            ],
        ], 200);
    }
}
