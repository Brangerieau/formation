<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TopicInput
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 150)]
        public string $title,
        #[Assert\NotBlank, Assert\Length(max: 10000)]
        public string $content,
        #[Assert\NotBlank, Assert\Length(max: 50)]
        public string $author,
    ) {
    }
}
