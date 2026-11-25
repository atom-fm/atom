<?php

declare(strict_types=1);

namespace App\Models;

use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;

#[Table("pokemon")]
final class Pokemon extends Model
{
    #[PrimaryKey(autoIncrement: false)]
    public int $id;

    #[Column]
    public string $name;

    #[Column("primary_type")]
    public string $primaryType;
}
