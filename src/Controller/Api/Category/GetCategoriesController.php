<?php

namespace App\Controller\Api\Category;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
class GetCategoriesController extends AbstractController
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private SerializerInterface $serializer
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Récupérer les paramètres de pagination
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(50, max(1, (int) $request->query->get('itemsPerPage', 20)));

        // Calculer l'offset
        $offset = ($page - 1) * $itemsPerPage;

        // Récupérer le nombre total de catégories
        $totalItems = $this->categoryRepository->count([]);

        // Récupérer les catégories paginées
        $categories = $this->categoryRepository->findBy(
            [],
            ['name' => 'ASC'],
            $itemsPerPage,
            $offset
        );

        // Sérialiser les catégories
        $serializedCategories = json_decode(
            $this->serializer->serialize(
                $categories,
                'json',
                ['groups' => ['category:lists']]
            ),
            true
        );

        // Formater la réponse selon le format demandé
        $response = [
            'message' => 'Liste des catégories récupérée avec succès',
            'status' => 200,
            'data' => [
                'itemsTotal' => $totalItems,
                'currentPage' => $page,
                'nombreParPage' => $itemsPerPage,
                'items' => $serializedCategories
            ]
        ];

        return $this->json($response, 200);
    }
}
