<?php

namespace App\Controller\Api\Event;

use App\Entity\OrganizerMembership;
use App\Entity\User;
use App\Exception\EventException;
use App\Service\Event\EventFiltersParser;
use App\Service\Event\EventListPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Événements de l'utilisateur connecté — `/api/events` restreint à ce qui le concerne.
 *
 * Même réponse et mêmes filtres que `/api/events` (voir {@see EventFiltersParser}),
 * seul l'ensemble listé change : les événements portés par les organisations dont
 * la personne est membre, brouillons compris (c'est une vue de gestion). Le
 * rattachement passe par `OrganizerMembership`, un événement n'ayant pas d'auteur
 * propre — il appartient à une organisation.
 *
 * `organizer=<id>` doit désigner une de **ses** organisations, sinon `403` : une
 * liste vide laisserait croire que l'organisation n'a pas d'événements.
 *
 * Comme pour `/api/organizers/me`, `ROLE_SUPER_ADMIN` ne donne pas d'appartenance :
 * un super administrateur membre d'aucune organisation reçoit une liste vide.
 * L'inventaire complet reste `/api/events`.
 */
#[AsController]
final class GetMyEventsController extends AbstractController
{
    public function __construct(
        private readonly EventFiltersParser $parser,
        private readonly EventListPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $filters = $this->parser->parse($request);

        if ($filters->organizerId !== null && !$this->belongsTo($user, $filters->organizerId)) {
            throw EventException::organisationHorsPerimetre();
        }

        [$page, $itemsPerPage] = $this->parser->pagination($request);

        return $this->json([
            'message' => 'Liste de vos événements récupérée avec succès',
            'status' => 200,
            'data' => $this->presenter->page($filters, $user, $page, $itemsPerPage),
        ], 200);
    }

    private function belongsTo(User $user, int $organizerId): bool
    {
        foreach ($user->getMemberships() as $membership) {
            /** @var OrganizerMembership $membership */
            if ($membership->getOrganizer()?->getId() === $organizerId) {
                return true;
            }
        }

        return false;
    }
}
