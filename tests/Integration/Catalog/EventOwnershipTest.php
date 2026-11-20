<?php

namespace App\Tests\Integration\Catalog;

use App\Entity\Location;
use App\Entity\Organizer;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Un lieu ou une organisation ne doit jamais emporter ses événements avec lui
 * (open-questions.md Q-05).
 *
 * Avant correction, `orphanRemoval: true` sur `Location::$events` et
 * `Organizer::$events` faisait supprimer par l'ORM tous les événements — et
 * leurs tarifs — d'un lieu ou d'une organisation supprimé, et `removeEvent()`
 * supprimait l'événement au lieu de le détacher. La suppression doit au
 * contraire être refusée tant que des événements y sont rattachés.
 *
 * ⚠️ Les entités sont **rechargées** depuis la base avant chaque opération
 * (`reload()`), comme dans une vraie requête. Sans cela, la collection
 * `$location->events` en mémoire serait vide (la fabrique ne renseigne que le
 * côté propriétaire) et l'ORM n'aurait rien à supprimer en cascade : les tests
 * passeraient même avec le défaut.
 */
final class EventOwnershipTest extends DatabaseTestCase
{
    private const FOREIGN_KEY_VIOLATION = '23503';

    // ------------------------------------------------------------------ lieu

    public function testRetirerUnEvenementDeLaListeDUnLieuNeLeSupprimePas(): void
    {
        $location = $this->data->location();
        $event = $this->data->event(location: $location);
        [$location, $event] = $this->reload($location, $event);
        self::assertTrue($location->getEvents()->contains($event), 'Pré-condition : collection chargée.');

        $location->removeEvent($event);
        $this->em()->flush();

        self::assertSame(
            $location->getId(),
            $this->fetchInt('SELECT location_id FROM event WHERE id = ?', [$event->getId()]),
            'L’événement existe toujours, rattaché au même lieu.'
        );
    }

    public function testUnLieuQuiAccueilleDesEvenementsNePeutPasEtreSupprime(): void
    {
        $location = $this->data->location();
        $event = $this->data->event(location: $location);
        $this->data->ticketType($event);
        [$location] = $this->reload($location);

        $this->assertDeletionRefused($location);

        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM event WHERE id = ?', [$event->getId()]));
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM ticket_type WHERE event_id = ?', [$event->getId()]));
    }

    public function testUnLieuSansEvenementPeutEtreSupprime(): void
    {
        $location = $this->data->location();
        $id = $location->getId();
        [$location] = $this->reload($location);

        $this->em()->remove($location);
        $this->em()->flush();

        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM location WHERE id = ?', [$id]));
    }

    // ---------------------------------------------------------- organisation

    public function testRetirerUnEvenementDeLaListeDUneOrganisationNeLeSupprimePas(): void
    {
        $organizer = $this->data->organizer();
        $event = $this->data->event($organizer);
        [$organizer, $event] = $this->reload($organizer, $event);
        self::assertTrue($organizer->getEvents()->contains($event), 'Pré-condition : collection chargée.');

        $organizer->removeEvent($event);
        $this->em()->flush();

        self::assertSame(
            $organizer->getId(),
            $this->fetchInt('SELECT organizer_id FROM event WHERE id = ?', [$event->getId()])
        );
    }

    public function testUneOrganisationQuiPorteDesEvenementsNePeutPasEtreSupprimee(): void
    {
        $owner = $this->data->user();
        $organizer = $this->data->organizer(owner: $owner);
        $event = $this->data->event($organizer);
        [$organizer] = $this->reload($organizer);

        $this->assertDeletionRefused($organizer);

        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM event WHERE id = ?', [$event->getId()]));
        self::assertSame(
            1,
            $this->fetchInt('SELECT COUNT(*) FROM organizer_membership WHERE organizer_id = ?', [$organizer->getId()]),
            'Les appartenances ne sont pas supprimées non plus.'
        );
    }

    public function testUneOrganisationSansEvenementPeutEtreSupprimeeAvecSesAppartenances(): void
    {
        $organizer = $this->data->organizer(owner: $this->data->user());
        $id = $organizer->getId();
        [$organizer] = $this->reload($organizer);

        $this->em()->remove($organizer);
        $this->em()->flush();

        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organizer WHERE id = ?', [$id]));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organizer_membership WHERE organizer_id = ?', [$id]));
    }

    // ---------------------------------------------------------------- outils

    /**
     * Vide l'EntityManager et relit les entités depuis la base.
     *
     * @return object[] dans le même ordre
     */
    private function reload(object ...$entities): array
    {
        $references = array_map(static fn (object $e) => [$e::class, $e->getId()], $entities);
        $this->em()->clear();

        return array_map(fn (array $ref) => $this->em()->find($ref[0], $ref[1]), $references);
    }

    private function assertDeletionRefused(Location|Organizer $entity): void
    {
        try {
            $this->em()->remove($entity);
            $this->em()->flush();
        } catch (DriverException $exception) {
            self::assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getSQLState(), $exception->getMessage());

            return;
        }

        self::fail('La suppression aurait dû être refusée par la base.');
    }
}
