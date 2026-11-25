<?php

declare(strict_types=1);

namespace App\Models;

use Atom\Database\Model;
use Atom\Database\Orm\Attributes\BelongsTo;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;

#[Table("team_members")]
final class TeamMember extends Model
{
    #[PrimaryKey]
    public int $id;

    #[Column("pokemon_id")]
    public int $pokemonId;

    #[BelongsTo(Pokemon::class, "pokemon_id")]
    public ?Pokemon $pokemon = null;
}
