<?php

namespace App\Controller\Api\Membership;

use App\Entity\User;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\OrganizerMembershipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Organisations de l'utilisateur connecté, avec son rôle dans chacune.
 *
 * C'est l'appel qui permet à une interface de proposer un sélecteur
 * d'organisation quand la personne en dirige plusieurs.
 */
#[AsController]
#[Route('/api/user/me/organizations', name: 'api_me_organizations', methods: ['GET'])]
final class GetMyOrganizationsController extends AbstractController
{
    public function __construct(
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $memberships = $this->membershipService->membershipsOf($user);

        return $this->json([
            'message' => 'Organisations de l’utilisateur récupérées avec succès',
            'status' => 200,
            'data' => [
                'itemsTotal' => count($memberships),
                'isSuperAdmin' => $user->isSuperAdmin(),
                'items' => $this->presenter->organizations($memberships),
            ],
        ], 200);
    }
}
