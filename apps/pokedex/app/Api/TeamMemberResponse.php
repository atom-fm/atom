<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\TeamMember;

final readonly class TeamMemberResponse
{
    public function __construct(
        public int $id,
        public int $pokemon_id,
        public ?PokemonResponse $pokemon
    ) {
    }

    public static function fromModel(TeamMember $member): self
    {
        return new self(
            $member->id,
            $member->pokemonId,
            $member->pokemon === null ? null : PokemonResponse::fromModel($member->pokemon)
        );
    }
}
