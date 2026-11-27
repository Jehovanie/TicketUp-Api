<?php

namespace App\Exception;

/** Erreurs de saisie des filtres de liste d'événements. */
final class EventException extends BusinessException
{
    public static function valeurInvalide(string $parametre, array $acceptes): self
    {
        return new self(
            sprintf('Le paramètre « %s » doit valoir : %s.', $parametre, implode(', ', $acceptes)),
            400
        );
    }

    public static function identifiantInvalide(string $parametre): self
    {
        return new self(sprintf('Le paramètre « %s » doit être un identifiant (entier positif).', $parametre), 400);
    }

    public static function dateInvalide(string $parametre): self
    {
        return new self(sprintf('Le paramètre « %s » doit être une date au format AAAA-MM-JJ.', $parametre), 400);
    }

    public static function periodeInversee(): self
    {
        return new self('« startDate » doit précéder ou égaler « endDate ».', 400);
    }

    public static function texteInvalide(string $parametre): self
    {
        return new self(sprintf('Le paramètre « %s » doit être une chaîne de caractères.', $parametre), 400);
    }

    public static function brouillonsReserves(): self
    {
        return new self('Les brouillons ne sont visibles que du super administrateur : filtrez sur « status=published ».', 403);
    }

    public static function organisationHorsPerimetre(): self
    {
        return new self('Cette organisation ne fait pas partie de vos organisations.', 403);
    }

    public static function introuvable(int $id): self
    {
        return new self(sprintf('Aucun événement ne porte l’identifiant %d.', $id), 404);
    }

    public static function publicationSansBillet(): self
    {
        return new self(
            'Impossible de publier : ajoutez au moins un type de billet à cet événement.',
            409
        );
    }

    public static function publicationTerminee(): self
    {
        return new self(
            'Impossible de publier : cet événement est déjà terminé (sa date de fin est passée).',
            409
        );
    }

    // ----------------------------------------------------------- Modification

    public static function aucuneModification(): self
    {
        return new self(
            'Aucun champ à modifier : fournissez au moins un champ parmi title, description, startedAt, endAt, category, location, status.',
            400
        );
    }

    public static function champRequis(string $champ): self
    {
        return new self(sprintf('Le champ « %s » est obligatoire et ne peut pas être vide.', $champ), 400);
    }

    public static function champTropLong(string $champ, int $maximum): self
    {
        return new self(sprintf('Le champ « %s » ne doit pas dépasser %d caractères.', $champ, $maximum), 400);
    }

    public static function champInvalide(string $champ, string $attendu): self
    {
        return new self(sprintf('Le champ « %s » est invalide : %s.', $champ, $attendu), 400);
    }

    public static function organisationNonModifiable(): self
    {
        return new self('L’organisation d’un événement ne peut pas être changée.', 400);
    }

    public static function datesIncoherentes(): self
    {
        return new self('La date de fin (« endAt ») doit être postérieure à la date de début (« startedAt »).', 400);
    }

    public static function publicationReservee(): self
    {
        return new self('Seul un responsable ou un administrateur de l’organisation peut modifier le statut de publication.', 403);
    }

    public static function depublicationImpossible(): self
    {
        return new self('Un événement publié ne peut pas être repassé en brouillon.', 409);
    }

    public static function evenementPublieTermine(): self
    {
        return new self('Un événement publié doit se terminer dans le futur : choisissez une date de fin à venir.', 409);
    }

    public static function categorieIntrouvable(int $id): self
    {
        return new self(sprintf('Aucune catégorie ne porte l’identifiant %d.', $id), 400);
    }

    public static function lieuIntrouvable(int $id): self
    {
        return new self(sprintf('Aucun lieu ne porte l’identifiant %d.', $id), 400);
    }

    // --------------------------------------------------------------- Création

    public static function corpsInvalide(): self
    {
        return new self(
            'Corps de requête invalide : envoyez un objet JSON (application/json), ou en multipart/form-data '
            . 'un champ « data » contenant ce JSON et jusqu’à 3 fichiers « images[] ». '
            . 'Si vous avez envoyé des images, leur poids total dépasse peut-être la limite du serveur.',
            400
        );
    }

    public static function organisationNonMembre(): self
    {
        return new self('Vous devez être membre de cette organisation pour lui créer un événement.', 403);
    }

    // ----------------------------------------------------------------- Images

    public static function tropDImages(int $recues, int $maximum): self
    {
        return new self(sprintf('Un événement compte au plus %d images (%d reçues).', $maximum, $recues), 400);
    }

    public static function imageNonRecue(string $nom, string $raison): self
    {
        return new self(sprintf('L’image « %s » n’a pas pu être reçue : %s.', $nom, $raison), 400);
    }

    public static function imageTypeInvalide(string $nom, string $type, array $acceptes): self
    {
        return new self(
            sprintf('L’image « %s » est de type %s : seuls %s sont acceptés.', $nom, $type, implode(', ', $acceptes)),
            400
        );
    }

    public static function imageTropLourde(string $nom, int $maximumOctets): self
    {
        return new self(
            sprintf('L’image « %s » dépasse la taille maximale de %d Mo.', $nom, intdiv($maximumOctets, 1024 * 1024)),
            400
        );
    }

    public static function stockageIndisponible(): self
    {
        return new self('Le stockage des images est momentanément indisponible : réessayez plus tard. Rien n’a été créé.', 503);
    }
}
