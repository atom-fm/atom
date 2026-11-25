<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Api\TeamMemberResponse;
use App\Models\Pokemon;
use App\Models\TeamMember;
use Atom\Api\Attributes\ResponseOf;
use Atom\Api\Responses\Conflict;
use Atom\Api\Responses\Created;
use Atom\Api\Responses\NoContent;
use Atom\Api\Responses\NotFound;
use Atom\Api\Responses\UnprocessableContent;
use Atom\Database\Exception\UniqueViolationException;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Attributes\FromRoute;
use Atom\Router\Attributes\ApiController;
use Atom\Router\Attributes\Delete;
use Atom\Router\Attributes\Get;
use Atom\Router\Attributes\Post;
use Atom\Router\Route;

#[ApiController("/api/team")]
final class TeamController
{
    /** @return list<TeamMemberResponse> */
    #[Get("", title: "List team members")]
    #[ResponseOf(TeamMemberResponse::class)]
    public function index(): array
    {
        return TeamMember::query()
            ->with("pokemon")
            ->orderBy("id")
            ->map(TeamMemberResponse::fromModel(...));
    }

    #[Get("members/{id}", name: "team.members.show", title: "Read a team member")]
    public function show(#[FromRoute] int $id): TeamMemberResponse|NotFound
    {
        $member = TeamMember::find($id, "pokemon");
        if ($member === null) {
            return new NotFound("Team member not found");
        }

        return TeamMemberResponse::fromModel($member);
    }

    #[Post("members", title: "Add a Pokémon to the team")]
    #[ResponseOf(TeamMemberResponse::class)]
    public function create(#[FromBody("pokemon_id")] int $pokemonId): Created|NotFound|Conflict|UnprocessableContent
    {
        if ($pokemonId < 1) {
            return new UnprocessableContent("pokemon_id must be a positive integer");
        }

        $pokemon = Pokemon::find($pokemonId);
        if ($pokemon === null) {
            return new NotFound("Pokémon not found");
        }

        $member = new TeamMember();
        $member->pokemonId = $pokemon->id;

        try {
            $member->save();
        } catch (UniqueViolationException) {
            return new Conflict("Pokémon is already on the team");
        }

        $member->pokemon = $pokemon;

        return new Created(
            Route::url("team.members.show", ["id" => $member->id]),
            TeamMemberResponse::fromModel($member)
        );
    }

    #[Delete("members/{id}", title: "Remove a team member")]
    public function delete(#[FromRoute] int $id): NoContent|NotFound
    {
        $member = TeamMember::find($id);
        if ($member === null || $member->delete() === 0) {
            return new NotFound("Team member not found");
        }

        return new NoContent();
    }
}
