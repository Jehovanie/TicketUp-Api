<?php

namespace App\Controller\Api\Organizer;

use App\Entity\User;
use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\MembershipRequestResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche complète d'une organisation, réservée à ses membres (`ORGANIZER_VIEW`).
 *
 * Contrairement à la ressource publique `GET /api/organizer/{id}`, elle porte
 * les comptes mobile money et le rôle de l'appelant : une page de gestion n'a
 * ainsi pas à recomposer ces données à partir de plusieurs appels.
 */
#[AsController]
#[Route('/api/organizers/{organizerId}', name: 'api_organizer_detail', methods: ['GET'], requirements: ['organizerId' => '\d+'])]
final class GetOrganizerController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::VIEW, $organizer);

        return $this->json([
            'message' => 'Organisation récupérée avec succès',
            'status' => 200,
            'data' => $this->presenter->detail($organizer, $user->getMembershipFor($organizer)),
        ], 200);
    }
}
