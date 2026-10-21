<?php

namespace App\Controller\Api;

use App\Dto\CategoryInput;
use App\Dto\PostInput;
use App\Dto\TopicInput;
use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Topic;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

// format: 'json' makes 404/422 errors render as JSON too.
#[Route('/api', name: 'api_', requirements: ['id' => Requirement::DIGITS], format: 'json')]
#[OA\Tag(name: 'Forum')]
class ForumApiController extends AbstractController
{
    #[Route('/categories', name: 'category_list', methods: ['GET'])]
    #[OA\Get(summary: 'Lister toutes les catégories du forum')]
    #[OA\Response(response: 200, description: 'Catégories triées par nom', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Category::class, groups: ['category']))))]
    public function categories(EntityManagerInterface $em): JsonResponse
    {
        return $this->json($em->getRepository(Category::class)->findBy([], ['name' => 'ASC']), context: ['groups' => ['category']]);
    }

    #[Route('/categories', name: 'category_create', methods: ['POST'])]
    #[OA\Post(summary: 'Créer une catégorie')]
    #[OA\Response(response: 201, description: 'Catégorie créée', content: new Model(type: Category::class, groups: ['category']))]
    #[OA\Response(response: 422, description: 'Payload invalide')]
    public function createCategory(#[MapRequestPayload] CategoryInput $input, EntityManagerInterface $em): JsonResponse
    {
        $category = new Category($input->name);
        $em->persist($category);
        $em->flush();

        return $this->json($category, Response::HTTP_CREATED, context: ['groups' => ['category']]);
    }

    #[Route('/categories/{id}/topics', name: 'topic_list', methods: ['GET'])]
    #[OA\Get(summary: "Lister les sujets d'une catégorie")]
    #[OA\Response(response: 200, description: 'Sujets de la catégorie, du plus récent au plus ancien', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Topic::class, groups: ['topic']))))]
    #[OA\Response(response: 404, description: 'Catégorie introuvable')]
    public function topics(Category $category): JsonResponse
    {
        return $this->json($category->getTopics(), context: ['groups' => ['topic']]);
    }

    #[Route('/categories/{id}/topics', name: 'topic_create', methods: ['POST'])]
    #[OA\Post(summary: 'Créer un sujet dans une catégorie')]
    #[OA\Response(response: 201, description: 'Sujet créé', content: new Model(type: Topic::class, groups: ['topic']))]
    #[OA\Response(response: 404, description: 'Catégorie introuvable')]
    #[OA\Response(response: 422, description: 'Payload invalide')]
    public function createTopic(Category $category, #[MapRequestPayload] TopicInput $input, EntityManagerInterface $em): JsonResponse
    {
        $topic = (new Topic($category))
            ->setTitle($input->title)
            ->setContent($input->content)
            ->setAuthor($input->author);
        $em->persist($topic);
        $em->flush();

        return $this->json($topic, Response::HTTP_CREATED, context: ['groups' => ['topic']]);
    }

    #[Route('/topics/{id}', name: 'topic_show', methods: ['GET'])]
    #[OA\Get(summary: 'Afficher un sujet avec tous ses messages')]
    #[OA\Response(response: 200, description: 'Sujet avec ses messages', content: new Model(type: Topic::class, groups: ['topic', 'topic:posts', 'post']))]
    #[OA\Response(response: 404, description: 'Sujet introuvable')]
    public function topic(Topic $topic): JsonResponse
    {
        return $this->json($topic, context: ['groups' => ['topic', 'topic:posts', 'post']]);
    }

    #[Route('/topics/{id}/posts', name: 'post_create', methods: ['POST'])]
    #[OA\Post(summary: 'Répondre à un sujet en ajoutant un message')]
    #[OA\Response(response: 201, description: 'Message créé', content: new Model(type: Post::class, groups: ['post']))]
    #[OA\Response(response: 404, description: 'Sujet introuvable')]
    #[OA\Response(response: 422, description: 'Payload invalide')]
    public function createPost(Topic $topic, #[MapRequestPayload] PostInput $input, EntityManagerInterface $em): JsonResponse
    {
        $post = (new Post($topic))
            ->setContent($input->content)
            ->setAuthor($input->author);
        $em->persist($post);
        $em->flush();

        return $this->json($post, Response::HTTP_CREATED, context: ['groups' => ['post']]);
    }

    #[Route('/categories/{id}', name: 'category_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Supprimer définitivement une catégorie, avec tous ses sujets et leurs messages', x: ['mcp-annotations' => ['destructiveHint' => true]])]
    #[OA\Response(response: 204, description: 'Catégorie supprimée')]
    #[OA\Response(response: 404, description: 'Catégorie introuvable')]
    public function deleteCategory(Category $category, EntityManagerInterface $em): Response
    {
        $em->remove($category);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/topics/{id}', name: 'topic_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Supprimer définitivement un sujet, avec tous ses messages', x: ['mcp-annotations' => ['destructiveHint' => true]])]
    #[OA\Response(response: 204, description: 'Sujet supprimé')]
    #[OA\Response(response: 404, description: 'Sujet introuvable')]
    public function deleteTopic(Topic $topic, EntityManagerInterface $em): Response
    {
        $em->remove($topic);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/posts/{id}', name: 'post_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Supprimer définitivement un message', x: ['mcp-annotations' => ['destructiveHint' => true]])]
    #[OA\Response(response: 204, description: 'Message supprimé')]
    #[OA\Response(response: 404, description: 'Message introuvable')]
    public function deletePost(Post $post, EntityManagerInterface $em): Response
    {
        $em->remove($post);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
