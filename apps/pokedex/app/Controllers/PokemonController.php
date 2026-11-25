<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Api\PokemonResponse;
use App\Models\Pokemon;
use Atom\Api\Attributes\ResponseOf;
use Atom\Api\Responses\NotFound;
use Atom\Database\Orm\PageResult;
use Atom\Hydrator\Attributes\FromQuery;
use Atom\Hydrator\Attributes\FromRoute;
use Atom\Router\Attributes\ApiController;
use Atom\Router\Attributes\Get;

#[ApiController("/api/pokemon")]
final class PokemonController
{
    /** @return PageResult<PokemonResponse> */
    #[Get("", title: "List Pokémon")]
    #[ResponseOf(PokemonResponse::class)]
    public function index(
        #[FromQuery] ?string $type = null,
        #[FromQuery] int $page = 1
    ): PageResult {
        return Pokemon::query()
            ->whereIfNotNull("primary_type", $type)
            ->orderBy("id")
            ->paginate($page, 3)
            ->map(PokemonResponse::fromModel(...));
    }

    #[Get("{id}", title: "Look up a Pokémon")]
    public function show(#[FromRoute] int $id): PokemonResponse|NotFound
    {
        $pokemon = Pokemon::find($id) ?? throw new NotFound("Pokémon not found");

        return PokemonResponse::fromModel($pokemon);
    }
}
