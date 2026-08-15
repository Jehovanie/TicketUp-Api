<?php

namespace App\Controller\Api\Membership;

use App\Entity\User;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\OrganizerMembershipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Organisations de l'utilisateur connecté — version réduite de `/api/organizers`.
 *
 * `/api/organizers` liste *toutes* les organisations de la plateforme, sans
 * filtre et sans notion de rôle. Ici la liste est restreinte aux organisations
 * auxquelles la personne appartient réellement (via `OrganizerMembership`), et
 * chaque ligne porte son rôle : c'est ce dont une interface a besoin pour
 * afficher un sélecteur d'organisation et décider quoi rendre modifiable.
 *
 * Le super administrateur ne fait pas exception : `ROLE_SUPER_ADMIN` donne accès
 * à tout, mais ne crée pas d'appartenance. S'il n'est membre d'aucune
 * organisation, il reçoit une liste vide — c'est bien la réponse à « à quoi
 * suis-je rattaché ? ». Pour l'inventaire complet, c'est `/api/organizers`.
 */
#[AsController]
final class GetMyOrganizersController extends AbstractController
{
    private const ITEMS_PER_PAGE_DEFAULT = 10;
    private const ITEMS_PER_PAGE_MAX = 20;

    public function __construct(
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(
            self::ITEMS_PER_PAGE_MAX,
            max(1, (int) $request->query->get('itemsPerPage', self::ITEMS_PER_PAGE_DEFAULT))
        );

        $itemsTotal = $this->membershipService->countMembershipsOf($user);
        $memberships = $this->membershipService->membershipsOf(
            $user,
            $itemsPerPage,
            ($page - 1) * $itemsPerPage
        );

        return $this->json([
            'message' => 'Organisations de l’utilisateur récupérées avec succès',
            'status' => 200,
            'data' => [
                'itemsTotal' => $itemsTotal,
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => $this->presenter->organizationSummaries($memberships),
            ],
        ], 200);
    }
}
