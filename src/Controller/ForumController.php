<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Topic;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ForumController extends AbstractController
{
    #[Route('/', name: 'forum_index')]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('forum/index.html.twig', [
            'categories' => $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/category/{id}', name: 'forum_category')]
    public function category(Category $category, Request $request, EntityManagerInterface $em): Response
    {
        $topic = new Topic($category);
        $form = $this->createFormBuilder($topic)
            ->add('title')
            ->add('author')
            ->add('content', TextareaType::class)
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($topic);
            $em->flush();

            return $this->redirectToRoute('forum_topic', ['id' => $topic->getId()]);
        }

        return $this->render('forum/category.html.twig', [
            'category' => $category,
            'form' => $form,
        ]);
    }

    #[Route('/topic/{id}', name: 'forum_topic')]
    public function topic(Topic $topic, Request $request, EntityManagerInterface $em): Response
    {
        $post = new Post($topic);
        $form = $this->createFormBuilder($post)
            ->add('author')
            ->add('content', TextareaType::class)
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($post);
            $em->flush();

            return $this->redirectToRoute('forum_topic', ['id' => $topic->getId()]);
        }

        return $this->render('forum/topic.html.twig', [
            'topic' => $topic,
            'form' => $form,
        ]);
    }
}
