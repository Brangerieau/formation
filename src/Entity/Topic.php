<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Topic
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['topic'])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank, Assert\Length(max: 150)]
    #[Groups(['topic'])]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank, Assert\Length(max: 10000)]
    #[Groups(['topic'])]
    private ?string $content = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank, Assert\Length(max: 50)]
    #[Groups(['topic'])]
    private ?string $author = null;

    #[ORM\Column]
    #[Groups(['topic'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'topics')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    /** @var Collection<int, Post> */
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'topic', cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[Groups(['topic:posts'])]
    private Collection $posts;

    public function __construct(Category $category, ?\DateTimeImmutable $createdAt = null)
    {
        $this->category = $category;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->posts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    /** @return Collection<int, Post> */
    public function getPosts(): Collection
    {
        return $this->posts;
    }
}
