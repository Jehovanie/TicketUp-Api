<?php

// src/ApiResource/RegisterResource.php
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Controller\Auth\RegisterController;

#[ApiResource(
    shortName: "Register",
    operations: [
        new Post(
            uriTemplate: '/auth/register',
            controller: RegisterController::class,
            openapi : new \ApiPlatform\OpenApi\Model\Operation(
                summary : 'Inscription utilisateur',
                description : 'Créer un nouveau compte utilisateur et retourne les tokens JWT',
                requestBody: new \ApiPlatform\OpenApi\Model\RequestBody(
                    content : new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'email' => new \ArrayObject(['type' => 'string', 'example' => 'admin@gmail.com']),
                                    'password' => new \ArrayObject(['type' => 'string', 'example' => 'admin']),
                                    'firstname' => new \ArrayObject(['type' => 'string', 'example' => 'Admin']),
                                    'lastname' => new \ArrayObject(['type' => 'string', 'example' => 'Super']),
                                    'phone' => new \ArrayObject(['type' => 'string', 'example' => '123465789']),
                                    'language' => new \ArrayObject(['type' => 'string', 'example' => 'fr']),
                                ],
                                'required' => ['email', 'password', 'firstname', 'lastname'],
                            ]
                        ]
                    ]),
                ),
                responses: [
                    '201' => [
                        'description' => 'Utilisateur créé',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'token' => ['type' => 'string'],
                                        'refresh_token' => ['type' => 'string'],
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ),
            read: false,
            write: false
        )
    ]
)]
class RegisterResource {}
