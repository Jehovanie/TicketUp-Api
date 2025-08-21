<?php

namespace App\Service\Security;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class JwtSecurityService{

    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenManagerInterface $rtManager,
    ){}

    public function generateAccessToken( User  $user ){
        $accessToken = $this->jwtManager->create($user);

        return $accessToken;
    }

    public function saveRefreshToken( User $user ): RefreshTokenInterface {
        $refreshToken = $this->rtManager->create($user);

        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setRefreshToken(); // auto
        $refreshToken->setValid((new \DateTimeImmutable('+30 days')));

        $this->rtManager->save($refreshToken);

        return $refreshToken;
    }

    public function generateRefreshToken( RefreshTokenInterface $refreshToken ){
        return $refreshToken->getRefreshToken();
    }
}