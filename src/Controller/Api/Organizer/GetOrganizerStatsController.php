<?php

namespace App\Controller\Api\Organizer;

use App\Security\Voter\OrganizerVoter;
use App\Service\Organizer\MembershipRequestResolver;
use App\Service\Organizer\OrganizerStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques de vente d'une organisation. Droit requis : `ORGANIZER_VIEW`.
 *
 * Paramètres facultatifs : `granularity=day|month` (défaut `day`), `from` et `to`
 * (`AAAA-MM-JJ`, défaut : 30 derniers jours / 12 derniers mois), `top` (1 à 10,
 * défaut 5). Calculs et conventions : {@see OrganizerStatsService}.
 *
 * `GET /api/admin/events/{id}` n'a pas pu servir de base : il ne renvoie que les
 * quotas des tarifs (aucune vente), n'a aucun contrôle d'accès et plante sur un
 * événement inconnu.
 */
#[AsController]
#[Route('/api/organizers/{organizerId}/stats', name: 'api_organizer_stats', methods: ['GET'], requirements: ['organizerId' => '\d+'])]
final class GetOrganizerStatsController extends AbstractController
{
    public function __construct(
        private readonly MembershipRequestResolver $resolver,
        private readonly OrganizerStatsService $stats,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        $organizer = $this->resolver->organizer($organizerId);
        $this->denyAccessUnlessGranted(OrganizerVoter::VIEW, $organizer);

        $query = $request->query;
        $period = $this->stats->resolvePeriod(
            $this->scalar($query->all(), 'granularity'),
            $this->scalar($query->all(), 'from'),
            $this->scalar($query->all(), 'to'),
        );
        $top = $this->stats->resolveTop($this->scalar($query->all(), 'top'));

        return $this->json([
            'message' => 'Statistiques de l’organisation récupérées avec succès',
            'status' => 200,
            'data' => $this->stats->compute($organizer, $period, $top),
        ], 200);
    }

    /** Un paramètre tableau (`?top[]=1`) est transmis sous une forme invalide : le validateur du service le refuse en 400. */
    private function scalar(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;

        return is_string($value) ? $value : (is_array($value) ? '[]' : null);
    }
}
