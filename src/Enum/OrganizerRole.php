<?php

namespace App\Enum;

use App\Exception\MembershipException;

/**
 * Rôle d'un utilisateur au sein d'une organisation.
 *
 * Les rôles sont hiérarchiques : un `owner` peut tout ce que peut un `admin`,
 * qui peut tout ce que peut un `member`. Les contrôles d'accès s'expriment donc
 * en « au moins ce niveau » (`isAtLeast()`) plutôt qu'en égalité stricte, ce qui
 * évite d'avoir à réécrire les conditions à chaque nouveau rôle intermédiaire.
 */
enum OrganizerRole: string
{
    /** Responsable de l'organisation. Une organisation en garde toujours au moins un. */
    case OWNER = 'owner';

    /** Gère l'organisation et ses membres, mais ne peut pas la supprimer. */
    case ADMIN = 'admin';

    /** Accès simple : consultation et gestion des événements. */
    case MEMBER = 'member';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Responsable',
            self::ADMIN => 'Administrateur',
            self::MEMBER => 'Membre',
        };
    }

    /**
     * Poids du rôle. Les paliers de 10 laissent la place à des rôles
     * intermédiaires sans avoir à renuméroter l'ensemble.
     */
    public function level(): int
    {
        return match ($this) {
            self::OWNER => 30,
            self::ADMIN => 20,
            self::MEMBER => 10,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /** Rôles disponibles, du plus élevé au plus faible. */
    public static function all(): array
    {
        return [self::OWNER, self::ADMIN, self::MEMBER];
    }

    /**
     * Conversion tolérante depuis une valeur d'entrée (« OWNER », « owner »…),
     * avec une erreur métier explicite plutôt qu'un `ValueError` brut.
     */
    public static function fromInput(?string $value): self
    {
        $role = self::tryFrom(strtolower(trim((string) $value)));

        if ($role === null) {
            throw MembershipException::roleInconnu((string) $value);
        }

        return $role;
    }
}
