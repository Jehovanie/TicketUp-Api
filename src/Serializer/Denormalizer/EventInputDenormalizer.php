<?php

namespace App\Serializer\Denormalizer;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Event;
use App\Entity\Category;
use App\Entity\Location;
use App\Entity\Organizer;
use App\Entity\TicketType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class EventInputDenormalizer implements DenormalizerInterface
{
    public function __construct(private EntityManagerInterface $em, private IriConverterInterface $iriConverter) {}

    public function supportsDenormalization($data, $type, $format = null, array $context = []): bool
    {
        return Event::class === $type;
    }

    public function denormalize($data, $type, $format = null, array $context = []): Event
    {
        $event = new Event();
        $event->setTitle($data['title'] ?? '');
        $event->setDescription($data['description'] ?? '');
        $event->setStartedAt(new \DateTimeImmutable($data['startedAt']));
        $event->setEndAt(new \DateTimeImmutable($data['endAt']));

        $event->setCategory($this->getCategory($data));
        $event->setOrganizer($this->getOrganizer($data));
        $event->setLocation($this->getLocation($data));


        // ---- ticket_type[] ----
        if (!empty($data['ticket_type'])) {
            foreach ($data['ticket_type'] as $ticketData) {
                $ticket = $this->getTickets($ticketData);
                $ticket->setEvent($event); // si relation bidirectionnelle

                $event->addTicketType($ticket);
            }
        }

        return $event;
    }

    private function getCategory($data)
    {
        if (isset($data['category']['id']) && $data['category']['id'] != null) {
            $category = $this->em->getRepository(Category::class)->find($data['category']['id']);
            if (!$category) {
                throw new \RuntimeException("Catégorie introuvable (id: {$data['category']['id']})");
            }
        } else if (!isset($data['category']['id']) || (isset($data['category']['id']) && $data['category']['id'] === null)) {

            $category = new Category();

            if (isset($data['category']['name'])) {
                $category->setName($data["category"]["name"]);
            }

            if (isset($data['category']['color'])) {
                $category->setColor($data["category"]["color"]);
            }
        } else {
            $category = $this->iriConverter->getResourceFromIri($data['category']);
        }

        return $category;
    }

    private function getOrganizer($data)
    {
        if (isset($data['organizer']['id'])) {
            $organizer = $this->em->getRepository(Organizer::class)->find($data['organizer']['id']);
            if (!$organizer) {
                throw new \RuntimeException("Organisateur introuvable (id: {$data['organizer']['id']})");
            }
        } else if (!isset($data['organizer']['id']) || (isset($data['organizer']['id']) && $data['organizer']['id'] === null)) {
            $organizer = new Organizer();

            if (isset($data['organizer']['name'])) {
                $organizer->setName($data["organizer"]["name"]);
            }

            if (isset($data['organizer']['email'])) {
                $organizer->setEmail($data["organizer"]["email"]);
            }

            if (isset($data['organizer']['phone'])) {
                $organizer->setPhone($data["organizer"]["phone"]);
            }

            if (isset($data['organizer']['website'])) {
                $organizer->setWebsite($data["organizer"]["website"]);
            }
        } else {
            $organizer = $this->iriConverter->getResourceFromIri($data['organizer']);
        }

        return $organizer;
    }

    private function getLocation($data)
    {
        if (isset($data['location']) && isset($data['location']['id'])) {
            $location = $this->em->getRepository(Location::class)->find($data['location']['id']);
            if (!$location) {
                throw new \RuntimeException("Lieu introuvable (id: {$data['location']['id']})");
            }
        } else if (!isset($data['location']['id']) || (isset($data['location']['id']) && $data['location']['id'] === null)) {
            $location = new Location();

            if (isset($data['location']['name'])) {
                $location->setName($data["location"]["name"]);
            }

            if (isset($data['location']['size'])) {
                $location->setSize($data["location"]["size"]);
            }
        } else {
            $location = $this->iriConverter->getResourceFromIri($data['location']);
        }

        return $location;
    }

    private function getTickets($ticketData)
    {
        $ticket = new TicketType();
        $ticket->setName($ticketData['name']);
        $ticket->setPrix($ticketData['prix']);
        $ticket->setQuantiteMax($ticketData['quantite_max']);
        return $ticket;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Event::class => true,
        ];
    }
}
