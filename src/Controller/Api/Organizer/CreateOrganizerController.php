<?php

namespace App\Controller\Api\Organizer;

use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Service\Organizer\MembershipPresenter;
use App\Service\Organizer\OrganizerMembershipService;
use App\Service\Organizer\OrganizerInputParser;
use App\Service\Organizer\PaymentMethodInputParser;
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
 * Corps attendu :
 * `{ "name": "…", "email": "…", "phone": "…", "website": "…",
 *    "paymentMethods": [ { "provider": "mvola", "phoneNumber": "034 12 345 67", "accountName": "RAKOTO Jean" } ] }`
 * — `name`, `email` et au moins un compte mobile money (MVola, Orange Money,
 * Airtel Money ; un seul par opérateur) sont obligatoires.
 */
#[AsController]
#[Route('/api/organizers', name: 'api_organizer_create', methods: ['POST'])]
final class CreateOrganizerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizerMembershipService $membershipService,
        private readonly MembershipPresenter $presenter,
        private readonly PaymentMethodInputParser $paymentParser,
        private readonly OrganizerInputParser $inputParser,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = json_decode($request->getContent() ?: '[]', true);
        $payload = is_array($payload) ? $payload : [];

        $fields = $this->inputParser->parse($payload, partial: false);

        $organizer = (new Organizer())
            ->setName($fields['name'])
            ->setEmail($fields['email'])
            ->setPhone($fields['phone'])
            ->setWebsite($fields['website']);

        // Validé avant tout accès à la base : une saisie fausse ne doit rien écrire.
        // Persistés en cascade avec l'organisation, dans la même transaction.
        foreach ($this->paymentParser->parse($payload['paymentMethods'] ?? null) as $method) {
            $organizer->addPaymentMethod($method);
        }

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
}
