<?php

namespace App\Service\Organizer;

use App\Exception\OrganizerException;

/**
 * Lecture et validation des champs d'identité d'une organisation
 * (`name`, `email`, `phone`, `website`), partagées par la création
 * (`POST /api/organizers`) et la modification (`PATCH /api/organizers/{id}`).
 *
 * - création (`$partial = false`) : `name` et `email` obligatoires, les quatre
 *   clés sont toujours renvoyées (`phone` / `website` à `null` si absents) ;
 * - modification (`$partial = true`) : seules les clés **présentes** sont lues
 *   et renvoyées. Une clé présente doit rester valide (`name` et `email` ne
 *   peuvent pas être vidés) ; `phone` et `website` peuvent être vidés avec
 *   `null` ou `""`.
 *
 * L'email n'est pas unique en base, on ne l'impose donc pas ici non plus.
 */
final class OrganizerInputParser
{
    private const MAX_NAME = 100;
    private const MAX_EMAIL = 100;
    private const MAX_PHONE = 50;
    private const MAX_WEBSITE = 100;

    /** @return array{name?: string, email?: string, phone?: ?string, website?: ?string} */
    public function parse(array $payload, bool $partial): array
    {
        $fields = [];

        if (!$partial || array_key_exists('name', $payload)) {
            $fields['name'] = $this->requiredText($payload, 'name', self::MAX_NAME);
        }

        if (!$partial || array_key_exists('email', $payload)) {
            $fields['email'] = $this->email($payload);
        }

        if (!$partial || array_key_exists('phone', $payload)) {
            $fields['phone'] = $this->optionalText($payload, 'phone', self::MAX_PHONE);
        }

        if (!$partial || array_key_exists('website', $payload)) {
            $fields['website'] = $this->website($payload);
        }

        return $fields;
    }

    private function requiredText(array $payload, string $champ, int $maximum): string
    {
        $valeur = trim((string) ($payload[$champ] ?? ''));

        if ($valeur === '') {
            throw OrganizerException::champRequis($champ);
        }

        if (mb_strlen($valeur) > $maximum) {
            throw OrganizerException::champTropLong($champ, $maximum);
        }

        return $valeur;
    }

    private function optionalText(array $payload, string $champ, int $maximum): ?string
    {
        $valeur = trim((string) ($payload[$champ] ?? ''));

        if ($valeur === '') {
            return null;
        }

        if (mb_strlen($valeur) > $maximum) {
            throw OrganizerException::champTropLong($champ, $maximum);
        }

        return $valeur;
    }

    private function email(array $payload): string
    {
        $email = strtolower($this->requiredText($payload, 'email', self::MAX_EMAIL));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw OrganizerException::emailInvalide($email);
        }

        return $email;
    }

    private function website(array $payload): ?string
    {
        $website = $this->optionalText($payload, 'website', self::MAX_WEBSITE);

        if ($website !== null && filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw OrganizerException::siteInvalide($website);
        }

        return $website;
    }
}
