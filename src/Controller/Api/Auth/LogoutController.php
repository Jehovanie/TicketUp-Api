<?php

namespace App\Controller\Api\Auth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Déconnexion : invalide les refresh tokens.
 *
 * Les access tokens (JWT) déjà émis ne peuvent pas être révoqués : ils restent
 * valables jusqu'à leur expiration (15 min). Le front doit les oublier.
 */
final class LogoutController extends AbstractController
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Déconnecte un appareil. Public : le refresh token est la preuve suffisante,
     * et l'access token a pu expirer. Toujours 200 pour un jeton inconnu
     * (idempotent, ne révèle pas si le jeton a existé).
     */
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? ($payload['refresh_token'] ?? null) : null;

        if (!is_string($token) || $token === '') {
            return $this->envelope('Le champ « refresh_token » est obligatoire.', 400);
        }

        $this->db->executeStatement('DELETE FROM refresh_tokens WHERE refresh_token = ?', [$token]);

        return $this->envelope('Déconnexion effectuée.', 200);
    }

    /** Déconnecte tous les appareils de l'utilisateur connecté. */
    #[Route('/api/auth/logout-all', name: 'api_auth_logout_all', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function logoutAll(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $revoked = $this->db->executeStatement(
            'DELETE FROM refresh_tokens WHERE username = ?',
            [$user->getUserIdentifier()]
        );

        return $this->envelope('Tous les appareils ont été déconnectés.', 200, ['revoked' => $revoked]);
    }

    private function envelope(string $message, int $status, ?array $data = null): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'status' => $status, 'data' => $data], $status);
    }
}
