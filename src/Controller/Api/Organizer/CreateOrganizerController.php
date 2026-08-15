<?php

namespace App\Controller\Api\Organizer;

use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Exception\OrganizerException;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\OrganizerMembershipService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Création d'une organisation par l'utilisateur connecté.
 *
 * `Organizer` n'exposait aucune opération d'écriture : la seule façon d'en
 * créer une était de l'imbriquer dans un `POST /api/events`, ce qui la laissait
 * sans aucun membre — donc invisible dans `/api/user/me/organizations` et
 * ingérable, personne n'ayant les droits dessus.
 *
 * Le créateur est donc rattaché comme **responsable** dans la foulée. C'est ce
 * qui garantit l'invariant « une organisation a toujours au moins un
 * responsable » dès sa naissance.
 *
 * Corps attendu : `{ "name": "…", "email": "…", "phone": "…", "website": "…" }`
 * — les deux premiers sont obligatoires.
 */
#[AsController]
#[Route('/api/organizers', name: 'api_organizer_create', methods: ['POST'])]
final class CreateOrganizerController extends AbstractController
{
    private const MAX_NAME = 100;
    private const MAX_EMAIL = 100;
    private const MAX_PHONE = 50;
    private const MAX_WEBSITE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = json_decode($request->getContent() ?: '[]', true);
        $payload = is_array($payload) ? $payload : [];

        $organizer = (new Organizer())
            ->setName($this->requiredText($payload, 'name', self::MAX_NAME))
            ->setEmail($this->email($payload))
            ->setPhone($this->optionalText($payload, 'phone', self::MAX_PHONE))
            ->setWebsite($this->website($payload));

        // `assign()` cherche une appartenance existante, ce qui suppose une
        // organisation déjà identifiée : on l'enregistre donc d'abord. La
        // transaction garantit qu'un échec du rattachement ne laisse pas
        // derrière lui une organisation sans responsable — donc ingérable.
        $membership = $this->em->wrapInTransaction(
            function () use ($organizer, $user) {
                $this->em->persist($organizer);
                $this->em->flush();

                return $this->membershipService->assign($user, $organizer, OrganizerRole::OWNER);
            }
        );

        return $this->json([
            'message' => sprintf('%s a été créée. Vous en êtes le responsable.', $organizer->getName()),
            'status' => 201,
            'data' => $this->presenter->organization($membership),
        ], 201);
    }

    private function requiredText(array $payload, string $champ, int $maximum): string
    {
        $valeur = trim((string) ($payload[$champ] ?? ''));

        if ($valeur === '') {
            throw OrganizerException::champRequis($champ);
        }

        if (mb_strlen($valeur) > $maximum) {
            throw OrganizerException::champTropLong($champ, $maximum);
        }

        return $valeur;
    }

    private function optionalText(array $payload, string $champ, int $maximum): ?string
    {
        $valeur = trim((string) ($payload[$champ] ?? ''));

        if ($valeur === '') {
            return null;
        }

        if (mb_strlen($valeur) > $maximum) {
            throw OrganizerException::champTropLong($champ, $maximum);
        }

        return $valeur;
    }

    private function email(array $payload): string
    {
        $email = strtolower($this->requiredText($payload, 'email', self::MAX_EMAIL));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw OrganizerException::emailInvalide($email);
        }

        return $email;
    }

    /**
     * La colonne `email` n'étant pas unique en base, deux organisations peuvent
     * partager une adresse — on ne l'interdit pas ici pour ne pas inventer une
     * règle que le schéma ne porte pas.
     */
    private function website(array $payload): ?string
    {
        $website = $this->optionalText($payload, 'website', self::MAX_WEBSITE);

        if ($website !== null && filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw OrganizerException::siteInvalide($website);
        }

        return $website;
    }
}
