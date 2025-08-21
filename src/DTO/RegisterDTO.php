<?php

// src/DTO/RegisterDTO.php
namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterDTO
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max: 72)]
    // #[Assert\NotCompromisedPassword]
    public string $password;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    public string $firstname;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    public string $lastname;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Length(max: 10)]
    public ?string $language = null;
}
