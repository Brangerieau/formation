<?php

namespace App\Dto;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DocSearchQuery
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 500)]
        #[OA\Property(description: 'La question ou les mots-clés à chercher')]
        public string $q,
    ) {
    }
}
