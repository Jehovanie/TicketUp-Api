<?php

namespace App\Service\Event;

use App\Exception\EventException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lit les paramètres de requête communs à `GET /api/events`, `GET /api/events/me`
 * et `GET /api/organizers/{id}/events`.
 *
 * Toute valeur inconnue ou mal formée est refusée en 400 avec la liste des
 * valeurs acceptées ; un paramètre vide (`?status=`) ou répété (`?status[]=`)
 * l'est aussi — sauf `title`, où une saisie vide équivaut à « pas de filtre »
 * (champ de recherche laissé vide).
 */
final class EventFiltersParser
{
    public const ITEMS_PER_PAGE_DEFAULT = 10;
    public const ITEMS_PER_PAGE_MAX = 20;

    public function parse(Request $request): EventFilters
    {
        $q = $request->query->all();

        $status = $this->choice($q, 'status', ['published', 'draft']);
        $startDate = $this->date($q, 'startDate');
        $endDate = $this->date($q, 'endDate');

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            throw EventException::periodeInversee();
        }

        return new EventFilters(
            title: $this->title($q),
            organizerId: $this->id($q, 'organizer'),
            categoryId: $this->id($q, 'category'),
            published: $status === null ? null : $status === 'published',
            period: $this->choice($q, 'period', [EventFilters::PERIOD_UPCOMING, EventFilters::PERIOD_ONGOING, EventFilters::PERIOD_PAST]),
            startDate: $startDate,
            endDate: $endDate,
            sort: $this->choice($q, 'sort', array_keys(EventFilters::SORTS)) ?? EventFilters::DEFAULT_SORT,
        );
    }

    /** @return array{0: int, 1: int} [page, itemsPerPage] — bornés comme sur les autres listes. */
    public function pagination(Request $request): array
    {
        return [
            max(1, (int) $request->query->get('page', 1)),
            min(self::ITEMS_PER_PAGE_MAX, max(1, (int) $request->query->get('itemsPerPage', self::ITEMS_PER_PAGE_DEFAULT))),
        ];
    }

    private function title(array $q): ?string
    {
        if (!array_key_exists('title', $q)) {
            return null;
        }
        if (!is_string($q['title'])) {
            throw EventException::texteInvalide('title');
        }

        $title = trim($q['title']);

        return $title === '' ? null : $title;
    }

    /** @param string[] $accepted */
    private function choice(array $q, string $name, array $accepted): ?string
    {
        if (!array_key_exists($name, $q)) {
            return null;
        }
        if (!is_string($q[$name]) || !in_array($q[$name], $accepted, true)) {
            throw EventException::valeurInvalide($name, $accepted);
        }

        return $q[$name];
    }

    private function id(array $q, string $name): ?int
    {
        if (!array_key_exists($name, $q)) {
            return null;
        }
        if (!is_string($q[$name]) || preg_match('/^[1-9]\d{0,17}$/', $q[$name]) !== 1) {
            throw EventException::identifiantInvalide($name);
        }

        return (int) $q[$name];
    }

    private function date(array $q, string $name): ?\DateTimeImmutable
    {
        if (!array_key_exists($name, $q)) {
            return null;
        }

        $date = is_string($q[$name]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$name]) : false;
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            throw EventException::dateInvalide($name);
        }

        return $date;
    }
}
