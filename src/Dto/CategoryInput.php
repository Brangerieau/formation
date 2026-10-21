<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CategoryInput
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 100)]
        public string $name,
    ) {
    }
}
