<?php

namespace App\Controller\Auth;

use App\Service\User\UserRegistrationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/auth/register', name: 'api_register', methods: ['POST'])]
final class RegisterController extends AbstractController
{
    public function __invoke(
        Request $request,
        UserRegistrationService $userRegistrationService,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true) ?? [];

        $data = $userRegistrationService->execute($payload);

        return $this->json([
            'token' => $data['token'],
            'refresh_token' => $data['refresh_token'],
            'user' => [
                'id' => $data['user']->getId(),
                'email' => $data['user']->getEmail(),
                'firstname' => $data['user']->getFirstname(),
                'lastname' => $data['user']->getLastname(),
                'phone' => $data['user']->getPhone(),
                'language' => $data['user']->getLanguage(),
            ]
        ], 201);
    }
}
