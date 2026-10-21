<?php

namespace App\Controller\Api;

use App\Dto\DocSearchQuery;
use App\Rag\DocIndex;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

// Exposed to the chatbot as an MCP tool, as every API route (see ApiToolRegistry).
#[Route('/api', name: 'api_', format: 'json')]
#[OA\Tag(name: 'Documentation')]
class DocApiController extends AbstractController
{
    #[Route('/docs/search', name: 'doc_search', methods: ['GET'])]
    #[OA\Get(summary: "Chercher dans la documentation du forum (guide d'utilisation et documentation technique) les sections qui répondent à une question")]
    #[OA\Response(response: 200, description: 'Sections les plus proches de la question, la meilleure en premier', content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'source', type: 'string', description: 'Fichier de documentation'),
        new OA\Property(property: 'title', type: 'string', description: 'Chemin des titres de la section'),
        new OA\Property(property: 'content', type: 'string', description: 'Texte de la section, en markdown'),
        new OA\Property(property: 'score', type: 'number', description: 'Similarité avec la question, de 0 à 1'),
    ])))]
    #[OA\Response(response: 422, description: 'Paramètres invalides')]
    public function search(#[MapQueryString(validationFailedStatusCode: 422)] DocSearchQuery $query, DocIndex $index): JsonResponse
    {
        // Unescaped: the tool result goes to the LLM as is, and "\u00e9" costs more tokens than "é".
        return $this->json($index->search($query->q), context: ['json_encode_options' => \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES]);
    }
}
