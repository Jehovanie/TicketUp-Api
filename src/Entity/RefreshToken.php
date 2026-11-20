<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * Refresh token (bundle Gesdinet) : permet d'obtenir un nouveau JWT sans
 * redemander le mot de passe.
 *
 * Lié à l'utilisateur par son email (`username`), sans clé étrangère : c'est
 * le fonctionnement du bundle. La cohérence avec `user` est maintenue par
 * {@see \App\EventListener\RefreshTokenUserListener}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(name: 'idx_refresh_token_username', columns: ['username'])]
#[ORM\Index(name: 'idx_refresh_token_valid', columns: ['valid'])]
class RefreshToken extends BaseRefreshToken
{
}
