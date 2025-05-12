<?php

namespace App\Controller\Admin\Event;

use App\Repository\EventRepository;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\JsonResponse;

#[AsController]
final class AdminEventDetailsController
{
    public function __construct(
        private EventRepository $eventRepository
    ) {}

    public function __invoke(int $id): JsonResponse
    {
        $now = new \DateTime();
        $statusTicket = [
            "global" => [],
            "actuel" => [],
            "filter" => [
                "time" => $now->format('Y-m-d H:i:s'),
                "value" => []
            ]
        ];

        $event = $this->eventRepository->find($id);
        $all_ticket = $event->getTicketType();

        $state_global = array_map(fn($ticket) => [
            $ticket->getName() => $ticket->getQuantiteMax()
        ], $all_ticket->toArray());

        $statusTicket["global"] = $state_global;
        $statusTicket["actuel"] = $state_global;
        $statusTicket["filter"]["value"] = $state_global;

        return new JsonResponse([
            "hello" => "world",
            "statusTicket" => $statusTicket
        ]);
    }
}
