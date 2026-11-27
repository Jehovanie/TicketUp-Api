<?php

namespace App\Controller\Api\Event;

use App\Entity\Event;
use App\Exception\EventException;
use App\Security\Voter\OrganizerVoter;
use App\Service\Event\EventCreator;
use App\Service\Event\EventImages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Création d'un événement : `POST /api/events` (opération déclarée sur {@see Event}).
 *
 * Deux formats de corps :
 * - `application/json` : l'objet événement, sans image (format historique) ;
 * - `multipart/form-data` : champ `data` = ce même objet en JSON, et de 0 à
 *   3 fichiers `images[]` (JPEG / PNG / WebP, 5 Mo max chacun).
 *
 * Droit requis : être membre de l'organisation visée (`ORGANIZER_VIEW`, comme
 * pour `PATCH /api/events/{id}`), ou `ROLE_SUPER_ADMIN`.
 *
 * Tout ou rien : si une image est refusée ou si le stockage échoue, aucun
 * événement n'est créé ({@see EventCreator}).
 */
#[AsController]
final class CreateEventController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly EventImages $images,
        private readonly EventCreator $creator,
    ) {}

    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        [$payload, $files] = $this->parse($request);

        // Les fichiers sont contrôlés avant tout accès à la base.
        $images = $this->images->validate($files);

        /** @var Event $event dénormalisé par EventInputDenormalizer */
        $event = $this->serializer->denormalize($payload, Event::class, 'json', ['groups' => ['events:create']]);

        if (!$this->isGranted(OrganizerVoter::VIEW, $event->getOrganizer())) {
            throw EventException::organisationNonMembre();
        }

        $this->creator->create($event, $images);

        return $this->json([
            'message' => sprintf('« %s » a été créé.', $event->getTitle()),
            'status' => 201,
            'data' => json_decode(
                $this->serializer->serialize($event, 'json', ['groups' => ['events:lists', 'events:details']]),
                true
            ),
        ], 201);
    }

    /** @return array{0: array, 1: mixed} corps décodé, fichiers bruts */
    private function parse(Request $request): array
    {
        if ('form' === $request->getContentTypeFormat()) {
            $data = $request->request->get('data');
            $payload = is_string($data) ? json_decode($data, true) : null;
            $files = $request->files->get('images');
        } else {
            $payload = json_decode($request->getContent(), true);
            $files = null;
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw EventException::corpsInvalide();
        }

        return [$payload, $files];
    }
}
