<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Pokemon;

final readonly class PokemonResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public string $primary_type
    ) {
    }

    public static function fromModel(Pokemon $pokemon): self
    {
        return new self($pokemon->id, $pokemon->name, $pokemon->primaryType);
    }
}
