<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
class Category
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['category'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['category'])]
    private string $name;

    /** @var Collection<int, Topic> */
    #[ORM\OneToMany(targetEntity: Topic::class, mappedBy: 'category', cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $topics;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->topics = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return Collection<int, Topic> */
    public function getTopics(): Collection
    {
        return $this->topics;
    }
}
