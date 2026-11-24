<?php

namespace App\Service\Event;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Location;
use App\Exception\EventException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Modification partielle d'un événement (`PATCH /api/events/{id}`).
 *
 * Champs : `title`, `description`, `startedAt`, `endAt`, `category` (`{id}` ou
 * identifiant, `null` pour retirer), `location` (idem, obligatoire), `status`.
 * Les billets ne se modifient pas ici ; l'organisation ne change jamais.
 *
 * Tout est validé **avant** la première modification : une saisie fausse ne
 * laisse l'entité à moitié changée.
 *
 * `status` n'accepte que `true` (publier, mêmes règles que {@see EventPublisher},
 * jugées sur les valeurs **après** modification). Repasser un événement publié en
 * brouillon est refusé : il peut déjà avoir des commandes (Q-06). Le droit de
 * toucher à `status` est contrôlé par l'appelant, pas ici.
 */
final class EventUpdater
{
    private const MAX_TITLE = 150;
    private const FIELDS = ['title', 'description', 'startedAt', 'endAt', 'category', 'location', 'status'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventPublisher $publisher,
    ) {}

    public static function touchesPublication(array $payload): bool
    {
        return array_key_exists('status', $payload);
    }

    public function update(Event $event, array $payload, ?\DateTimeImmutable $now = null): Event
    {
        $now ??= new \DateTimeImmutable();

        if (array_key_exists('organizer', $payload)) {
            throw EventException::organisationNonModifiable();
        }

        if (array_intersect(self::FIELDS, array_keys($payload)) === []) {
            throw EventException::aucuneModification();
        }

        // --- validation, sans rien toucher à l'entité ---------------------------
        $title = array_key_exists('title', $payload) ? $this->title($payload['title']) : null;
        $description = array_key_exists('description', $payload) ? $this->description($payload['description']) : null;
        $startedAt = array_key_exists('startedAt', $payload) ? $this->date('startedAt', $payload['startedAt']) : null;
        $endAt = array_key_exists('endAt', $payload) ? $this->date('endAt', $payload['endAt']) : null;
        $category = array_key_exists('category', $payload) ? $this->category($payload['category']) : false;
        $location = array_key_exists('location', $payload) ? $this->location($payload['location']) : null;
        $status = array_key_exists('status', $payload) ? $this->status($payload['status']) : null;

        if ($status === false && $event->isStatus() === true) {
            throw EventException::depublicationImpossible();
        }
        $publish = $status === true;

        $effectiveStart = $startedAt ?? $event->getStartedAt();
        $effectiveEnd = $endAt ?? $event->getEndAt();

        if (($startedAt !== null || $endAt !== null) && $effectiveEnd <= $effectiveStart) {
            throw EventException::datesIncoherentes();
        }

        $willBePublished = $event->isStatus() === true || $publish;

        if ($publish && $event->isStatus() !== true) {
            $this->publisher->assertPublishable($event, $effectiveEnd, $now);
        } elseif ($willBePublished && $endAt !== null && $effectiveEnd <= $now) {
            // Pas de contournement : un événement publié ne peut pas être « terminé » par une modification.
            throw EventException::evenementPublieTermine();
        }

        // --- application ---------------------------------------------------------
        $title !== null && $event->setTitle($title);
        $description !== null && $event->setDescription($description);
        $startedAt !== null && $event->setStartedAt($startedAt);
        $endAt !== null && $event->setEndAt($endAt);
        $category !== false && $event->setCategory($category);
        $location !== null && $event->setLocation($location);
        $publish && $event->setStatus(true);

        // `Event` n'a pas de `PreUpdate` (Q-29).
        $event->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $event;
    }

    private function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';

        if ($title === '') {
            throw EventException::champRequis('title');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw EventException::champTropLong('title', self::MAX_TITLE);
        }

        return $title;
    }

    private function description(mixed $value): string
    {
        if (!is_string($value)) {
            throw EventException::champInvalide('description', 'une chaîne de caractères est attendue');
        }

        return trim($value);
    }

    /** Date ISO 8601, ramenée au fuseau du serveur (celui des comparaisons `period`, `now`…). */
    private function date(string $field, mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            throw EventException::champInvalide($field, 'une date ISO 8601 est attendue, ex. 2027-01-15T18:00:00+03:00');
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            throw EventException::champInvalide($field, 'une date ISO 8601 est attendue, ex. 2027-01-15T18:00:00+03:00');
        }
    }

    private function category(mixed $value): ?Category
    {
        if ($value === null) {
            return null;
        }

        $id = $this->id('category', $value);

        return $this->em->find(Category::class, $id) ?? throw EventException::categorieIntrouvable($id);
    }

    private function location(mixed $value): Location
    {
        if ($value === null) {
            throw EventException::champRequis('location');
        }

        $id = $this->id('location', $value);

        return $this->em->find(Location::class, $id) ?? throw EventException::lieuIntrouvable($id);
    }

    /** Accepte `3` ou `{"id": 3}` (la forme de `POST /api/events`). */
    private function id(string $field, mixed $value): int
    {
        $raw = is_array($value) ? ($value['id'] ?? null) : $value;

        if (!(is_int($raw) || (is_string($raw) && ctype_digit($raw))) || (int) $raw < 1) {
            throw EventException::champInvalide($field, 'un identifiant (ou {"id": …}) est attendu');
        }

        return (int) $raw;
    }

    /** `true` = publier ; `false` = rester brouillon (sans effet sur un brouillon, refusé sur un publié). */
    private function status(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw EventException::champInvalide('status', 'true (publier) ou false est attendu');
        }

        return $value;
    }
}
