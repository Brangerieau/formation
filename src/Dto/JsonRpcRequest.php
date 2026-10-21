<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class JsonRpcRequest
{
    public function __construct(
        #[Assert\EqualTo('2.0')]
        public string $jsonrpc,
        #[Assert\NotBlank]
        public string $method,
        // No id means a notification: the server must not reply.
        public int|string|null $id = null,
        public array $params = [],
    ) {
    }
}
