<?php

namespace App\Service\Event;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Enregistre un nouvel événement et ses images, **tout ou rien**.
 *
 * L'identifiant de l'événement sert de préfixe aux clés S3 : il faut donc
 * l'insérer avant d'envoyer les images. Le tout se fait dans une transaction :
 * si l'envoi échoue, l'insertion est annulée ; si l'écriture finale en base
 * échoue, les images déjà envoyées sont supprimées.
 */
final class EventCreator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventImages $images,
    ) {}

    /** @param list<UploadedFile> $images déjà validées par {@see EventImages::validate()} */
    public function create(Event $event, array $images = []): Event
    {
        $storedKeys = [];

        try {
            $this->em->wrapInTransaction(function () use ($event, $images, &$storedKeys): void {
                $this->em->persist($event);
                $this->em->flush();

                if ([] !== $images) {
                    $storedKeys = $this->images->store($event, $images);
                    $event->setImages($storedKeys);
                    $this->em->flush();
                }
            });
        } catch (\Throwable $e) {
            $this->images->delete($storedKeys);

            throw $e;
        }

        return $event;
    }
}
