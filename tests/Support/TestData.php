<?php

namespace App\Tests\Support;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Location;
use App\Entity\Organizer;
use App\Entity\OrganizerMembership;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrganizerRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fabrique de données minimales pour les tests.
 *
 * Chaque méthode persiste et flushe immédiatement : l'entité renvoyée a un
 * identifiant utilisable tout de suite. Les valeurs par défaut sont valides ;
 * un test ne précise que ce qui compte pour lui.
 */
final class TestData
{
    private int $sequence = 0;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<string> $roles rôles globaux, ex. [User::ROLE_SUPER_ADMIN] */
    public function user(?string $email = null, array $roles = []): User
    {
        $n = ++$this->sequence;

        $user = (new User())
            ->setEmail($email ?? sprintf('utilisateur%d@test.mg', $n))
            ->setFirstname('Prénom' . $n)
            ->setLastname('Nom' . $n)
            ->setPhone('+261 34 00 000 ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT))
            ->setRoles($roles)
            // Haché factice : l'authentification des tests passe par un JWT signé,
            // jamais par le mot de passe.
            ->setPassword('$2y$04$test.hash.not.used.for.login.........................');

        return $this->save($user);
    }

    public function organizer(?string $name = null, ?User $owner = null): Organizer
    {
        $organizer = (new Organizer())
            ->setName($name ?? 'Organisation ' . ++$this->sequence)
            ->setEmail(sprintf('contact%d@organisation.mg', $this->sequence));

        $this->save($organizer);

        if ($owner !== null) {
            $this->membership($owner, $organizer, OrganizerRole::OWNER);
        }

        return $organizer;
    }

    public function membership(User $user, Organizer $organizer, OrganizerRole $role = OrganizerRole::MEMBER): OrganizerMembership
    {
        $membership = (new OrganizerMembership())
            ->setUser($user)
            ->setOrganizer($organizer)
            ->setRole($role);

        $user->addMembership($membership);
        $organizer->addMembership($membership);

        return $this->save($membership);
    }

    public function location(int $size = 1000): Location
    {
        return $this->save(
            (new Location())->setName('Salle ' . ++$this->sequence)->setSize($size)
        );
    }

    public function category(): Category
    {
        return $this->save(
            (new Category())->setName('Catégorie ' . ++$this->sequence)->setColor('#123456')
        );
    }

    /**
     * Événement publié, à venir (dans 10 jours, pour 3 heures) par défaut.
     *
     * @param string $startsAt expression relative acceptée par DateTimeImmutable, ex. '-2 hours'
     */
    public function event(
        ?Organizer $organizer = null,
        bool $published = true,
        string $startsAt = '+10 days',
        int $durationHours = 3,
        ?Location $location = null,
    ): Event {
        $start = new \DateTimeImmutable($startsAt);

        $event = new Event();
        $event->setTitle('Événement ' . ++$this->sequence);
        $event->setDescription('Description de test.');
        $event->setStartedAt($start);
        $event->setEndAt($start->modify(sprintf('+%d hours', $durationHours)));
        $event->setStatus($published);
        $event->setOrganizer($organizer ?? $this->organizer());
        $event->setLocation($location ?? $this->location());
        $event->setCategory($this->category());

        return $this->save($event);
    }

    public function ticketType(Event $event, string $name = 'Entrée générale', int $prix = 20000, int $quantiteMax = 100): TicketType
    {
        $ticketType = (new TicketType())
            ->setName($name)
            ->setPrix($prix)
            ->setQuantiteMax($quantiteMax);

        $event->addTicketType($ticketType);

        return $this->save($ticketType);
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    public function save(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
