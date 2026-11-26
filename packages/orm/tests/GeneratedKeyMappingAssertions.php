<?php

declare(strict_types=1);

namespace Atom\Tests\Database;

use Atom\Database\Db;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;
use Atom\Database\Sql\Query;

trait GeneratedKeyMappingAssertions
{
    private function assertGeneratedKeysMatchMappedProperties(Db $db): void
    {
        $integer = new #[Table("key_mapping_fixture")] class extends GeneratedKeyMappingModel {
            #[PrimaryKey("GeneratedKey")]
            public ?int $key = null;
        };
        $string = new #[Table("key_mapping_fixture")] class extends GeneratedKeyMappingModel {
            #[PrimaryKey("GeneratedKey")]
            public ?string $key = null;
        };
        $float = new #[Table("key_mapping_fixture")] class extends GeneratedKeyMappingModel {
            #[PrimaryKey("GeneratedKey")]
            public ?float $key = null;
        };

        foreach ([[$integer, 1], [$string, "2"], [$float, 3.0]] as [$model, $expected]) {
            $model->label = get_debug_type($expected);
            self::assertSame(1, $db->save($model));
            self::assertSame($expected, $model->key);
            $storedKey = $db->scalar(Query::select("key_mapping_fixture")
                ->columns("GeneratedKey")->where("label", $model->label));
            self::assertSame((string) $expected, (string) $storedKey);
        }
    }
}

abstract class GeneratedKeyMappingModel extends Model
{
    #[Column]
    public string $label;
}
