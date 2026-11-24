<?php

namespace App\Controller\Api\Organizer;

use App\Entity\User;
use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\MembershipRequestResolver;
use App\Service\Organizer\OrganizerUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modification partielle d'une organisation. Droit requis : `ORGANIZER_EDIT`
 * (administrateur ou plus).
 *
 * Corps : n'importe quel sous-ensemble de `name`, `email`, `phone`, `website`,
 * `paymentMethods`. Voir {@see OrganizerUpdater} pour les règles.
 */
#[AsController]
#[Route('/api/organizers/{organizerId}', name: 'api_organizer_update', methods: ['PATCH'], requirements: ['organizerId' => '\d+'])]
final class UpdateOrganizerController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly OrganizerUpdater $updater,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::EDIT, $organizer);

        $this->updater->update($organizer, $this->resolver->payload($request));

        return $this->json([
            'message' => sprintf('%s a été mise à jour.', $organizer->getName()),
            'status' => 200,
            'data' => $this->presenter->detail($organizer, $user->getMembershipFor($organizer)),
        ], 200);
    }
}
