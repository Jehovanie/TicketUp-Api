<?php

namespace App\Controller\Api\Organizer;

use App\Security\Voter\OrganizerVoter;
use App\Service\Event\EventFiltersParser;
use App\Service\Event\EventListPresenter;
use App\Service\Organizer\MembershipRequestResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Événements d'**une** organisation (`GET /api/events/me`, lui, agrège toutes
 * celles de l'utilisateur). Droit requis : `ORGANIZER_VIEW`.
 *
 * Mêmes filtres, tri et pagination que `/api/events` (voir {@see EventFiltersParser}),
 * brouillons compris, avec l'organisation **fixée par l'URL** : un éventuel
 * `?organizer=` est ignoré.
 */
#[AsController]
#[Route('/api/organizers/{organizerId}/events', name: 'api_organizer_events', methods: ['GET'], requirements: ['organizerId' => '\d+'])]
final class GetOrganizerEventsController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly EventFiltersParser $parser,
        private readonly EventListPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::VIEW, $organizer);

        $filters = $this->parser->parse($request)->withOrganizer($organizer->getId());
        [$page, $itemsPerPage] = $this->parser->pagination($request);

        return $this->json([
            'message' => 'Événements de l’organisation récupérés avec succès',
            'status' => 200,
            'data' => ['organizer' => ['id' => $organizer->getId(), 'name' => $organizer->getName()]]
                + $this->presenter->page($filters, null, $page, $itemsPerPage),
        ], 200);
    }
}
