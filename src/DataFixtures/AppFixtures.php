<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Topic;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class AppFixtures extends Fixture
{
    private const CATEGORIES = [
        'Général',
        'PHP',
        'Symfony',
        'Doctrine',
        'DevOps & Docker',
        'Hors-sujet',
    ];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        // Same data on every load, so the trainees all see the same forum.
        $faker->seed(2026);

        // A limited pool, so the same people show up across topics.
        $authors = [];
        for ($i = 0; $i < 30; ++$i) {
            $authors[] = $faker->unique()->userName();
        }

        foreach (self::CATEGORIES as $name) {
            $category = new Category($name);
            $manager->persist($category);

            for ($t = 0; $t < 10; ++$t) {
                $topicDate = $faker->dateTimeBetween('-1 year', '-1 day');
                $topic = (new Topic($category, \DateTimeImmutable::createFromMutable($topicDate)))
                    ->setTitle(rtrim($faker->realText(70), '.'))
                    ->setAuthor($faker->randomElement($authors))
                    ->setContent($faker->realText($faker->numberBetween(100, 1000)));
                $manager->persist($topic);

                for ($p = 0, $replies = $faker->numberBetween(0, 15); $p < $replies; ++$p) {
                    $postDate = \DateTimeImmutable::createFromMutable($faker->dateTimeBetween($topicDate, 'now'));
                    $manager->persist(
                        (new Post($topic, $postDate))
                            ->setAuthor($faker->randomElement($authors))
                            ->setContent($faker->realText($faker->numberBetween(30, 600)))
                    );
                }
            }
        }

        $manager->flush();
    }
}
