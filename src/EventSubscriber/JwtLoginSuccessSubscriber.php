<?php 

namespace App\EventSubscriber;

use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class JwtLoginSuccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RefreshTokenManagerInterface $refreshTokenManager
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [AuthenticationSuccessEvent::class => 'onAuthSuccess'];
    }

    public function onAuthSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        $user = $event->getUser();
        if (!$user) { return; }

        $refreshToken = $this->refreshTokenManager->create();
        $refreshToken->setUsername($user->getUserIdentifier()); // email
        $refreshToken->setRefreshToken(); // auto
        $refreshToken->setValid((new \DateTime())->modify('+30 days'));
        $this->refreshTokenManager->save($refreshToken);

        $data['refresh_token'] = $refreshToken->getRefreshToken();
        $event->setData($data);
    }
}
