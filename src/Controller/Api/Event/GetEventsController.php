<?php

namespace App\Controller\Api\Event;

use App\Entity\User;
use App\Exception\EventException;
use App\Service\Event\EventFiltersParser;
use App\Service\Event\EventListPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Catalogue des événements.
 *
 * Accès public, mais la **visibilité dépend de l'appelant** :
 * - tout le monde (anonyme compris) ne voit que les événements **publiés** ;
 * - le super administrateur (`ROLE_SUPER_ADMIN`, jeton JWT) garde la vue de
 *   gestion : brouillons compris.
 * Réclamer les brouillons (`status=draft`) sans être super administrateur → 403.
 *
 * Mêmes filtres, tri et pagination que `GET /api/events/me` : voir
 * {@see EventFiltersParser}.
 */
#[AsController]
class GetEventsController extends AbstractController
{
    public function __construct(
        private readonly EventFiltersParser $parser,
        private readonly EventListPresenter $presenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $filters = $this->parser->parse($request);

        if (!$user instanceof User || !$user->isSuperAdmin()) {
            if ($filters->published === false) {
                throw EventException::brouillonsReserves();
            }
            $filters = $filters->withPublished(true);
        }

        [$page, $itemsPerPage] = $this->parser->pagination($request);

        return $this->json([
            'message' => 'Liste des événements récupérée avec succès',
            'status' => 200,
            'data' => $this->presenter->page($filters, null, $page, $itemsPerPage),
        ], 200);
    }
}
