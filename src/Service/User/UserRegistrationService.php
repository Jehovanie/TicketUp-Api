<?php

namespace App\Service\User;

use App\Entity\User;
use App\DTO\RegisterDTO;
use App\Repository\UserRepository;
use App\Service\Security\JwtSecurityService;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRegistrationService {

    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
        private JwtSecurityService $jwtSecurityService,
    ) {}

    public function execute( $registrationPayload ): array {

        $registerDTO = $this->createRegisterDTO( $registrationPayload );

        if( !$this->validateDTO($registerDTO) ) {
            throw new \InvalidArgumentException('Invalid registration data');
        }

        $user  = $this->createUserFromDTO($registerDTO);

        $this->saveUser($user);
        
        return [
            'user' => $user,
            'token' => $this->jwtSecurityService->generateAccessToken($user),
            'refresh_token' => $this->jwtSecurityService->generateRefreshToken($this->jwtSecurityService->saveRefreshToken($user)),
        ];
    }


    public function createRegisterDTO( $payload ) : RegisterDTO {

        $dto = new RegisterDTO();

        $dto->email = strtolower(trim($payload['email'] ?? ''));
        $dto->password = (string) ($payload['password'] ?? '');
        $dto->firstname = (string) ($payload['firstname'] ?? '');
        $dto->lastname = (string) ($payload['lastname'] ?? '');
        $dto->phone = $payload['phone'] ?? null;
        $dto->language = $payload['language'] ?? null;

        return $dto;
    }



    public function validateDTO(RegisterDTO $dto): bool {
        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            throw new \InvalidArgumentException((string) $violations);
        }

        return true;
    }

    public function createUserFromDTO(RegisterDTO $user_dto): User {

        $user = (new User())
            ->setEmail($user_dto->email)
            ->setFirstname($user_dto->firstname)
            ->setLastname($user_dto->lastname)
            ->setPhone($user_dto->phone)
            ->setLanguage($user_dto->language)
            ->setRoles(['ROLE_USER']);

        $user->setPassword($this->hasher->hashPassword($user, $user_dto->password));

        return $user;
    }


    public function saveUser(User $user){
       $this->userRepository->saveUser($user);
    }
}