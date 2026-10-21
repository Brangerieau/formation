<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Post
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['post'])]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank, Assert\Length(max: 10000)]
    #[Groups(['post'])]
    private ?string $content = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank, Assert\Length(max: 50)]
    #[Groups(['post'])]
    private ?string $author = null;

    #[ORM\Column]
    #[Groups(['post'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'posts')]
    #[ORM\JoinColumn(nullable: false)]
    private Topic $topic;

    public function __construct(Topic $topic, ?\DateTimeImmutable $createdAt = null)
    {
        $this->topic = $topic;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTopic(): Topic
    {
        return $this->topic;
    }
}
