<?php

namespace App\Service\Organizer;

use App\Entity\Organizer;
use App\Entity\OrganizerPaymentMethod;
use App\Exception\OrganizerException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Modification partielle d'une organisation (`PATCH /api/organizers/{id}`).
 *
 * Tout est validé **avant** la première modification : une saisie fausse ne
 * laisse l'entité à moitié changée ni en mémoire ni en base.
 *
 * `paymentMethods`, s'il est fourni, **remplace** la liste complète (mêmes
 * règles qu'à la création : au moins un compte, un seul par opérateur).
 * Le remplacement se fait par opérateur : un compte conservé est mis à jour sur
 * place (son identifiant et sa date de création survivent), un opérateur absent
 * de la nouvelle liste est supprimé, un nouvel opérateur est ajouté.
 */
final class OrganizerUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizerInputParser $inputParser,
        private readonly PaymentMethodInputParser $paymentParser,
    ) {}

    public function update(Organizer $organizer, array $payload): Organizer
    {
        $fields = $this->inputParser->parse($payload, partial: true);
        $methods = array_key_exists('paymentMethods', $payload)
            ? $this->paymentParser->parse($payload['paymentMethods'])
            : null;

        if ($fields === [] && $methods === null) {
            throw OrganizerException::aucuneModification();
        }

        foreach ($fields as $field => $value) {
            match ($field) {
                'name' => $organizer->setName($value),
                'email' => $organizer->setEmail($value),
                'phone' => $organizer->setPhone($value),
                'website' => $organizer->setWebsite($value),
            };
        }

        if ($methods !== null) {
            $this->syncPaymentMethods($organizer, $methods);
        }

        // `Organizer` n'a pas de `PreUpdate` (Q-29) et un changement limité aux
        // comptes ne le déclencherait de toute façon pas.
        $organizer->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $organizer;
    }

    /** @param OrganizerPaymentMethod[] $incoming comptes validés, un par opérateur */
    private function syncPaymentMethods(Organizer $organizer, array $incoming): void
    {
        $existing = [];
        foreach ($organizer->getPaymentMethods() as $method) {
            $existing[$method->getProvider()->value] = $method;
        }

        $kept = [];
        foreach ($incoming as $new) {
            $key = $new->getProvider()->value;
            $kept[$key] = true;

            if (isset($existing[$key])) {
                $existing[$key]
                    ->setPhoneNumber($new->getPhoneNumber())
                    ->setAccountName($new->getAccountName());
            } else {
                $organizer->addPaymentMethod($new);
            }
        }

        foreach ($existing as $key => $method) {
            if (!isset($kept[$key])) {
                $organizer->removePaymentMethod($method);
            }
        }
    }
}
