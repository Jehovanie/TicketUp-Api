<?php

namespace App\Controller\Auth;

use App\DTO\RegisterDTO;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/auth/register', name: 'api_register', methods: ['POST'])]
final class RegisterController extends AbstractController
{
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        ValidatorInterface $validator,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenManagerInterface $rtManager,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true) ?? [];

        $dto = new RegisterDTO();
        $dto->email = strtolower(trim($payload['email'] ?? ''));
        $dto->password = (string) ($payload['password'] ?? '');
        $dto->firstname = (string) ($payload['firstname'] ?? '');
        $dto->lastname = (string) ($payload['lastname'] ?? '');
        $dto->phone = $payload['phone'] ?? null;
        $dto->language = $payload['language'] ?? null;

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return $this->json(['error' => (string) $violations], 422);
        }
        $user = (new User())
            ->setEmail($dto->email)
            ->setFirstname($dto->firstname)
            ->setLastname($dto->lastname)
            ->setPhone($dto->phone)
            ->setLanguage($dto->language)
            ->setRoles(['ROLE_USER']);

        $user->setPassword($hasher->hashPassword($user, $dto->password));
        $em->persist($user);
        $em->flush();

        // Générer l’access token
        $accessToken = $jwtManager->create($user);

        // Générer le refresh token (30 jours par défaut, cf. config bundle)
        $refresh = $rtManager->create();
        $refresh->setUsername($user->getUserIdentifier());
        $refresh->setRefreshToken(); // auto
        $refresh->setValid((new \DateTimeImmutable('+30 days')));
        $rtManager->save($refresh);

        return $this->json([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'language' => $user->getLanguage(),
                'roles' => $user->getRoles(),
            ],
        ], 201);
    }
}
