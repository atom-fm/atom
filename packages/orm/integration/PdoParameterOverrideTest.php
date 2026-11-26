<?php

declare(strict_types=1);

namespace Atom\Tests\Database\Integration;

use Atom\Database\Db;
use Atom\Database\Orm\PdoConnection;
use Atom\Database\Sql\Compiler\MySqlCompiler;
use Atom\Database\Sql\Compiler\PostgresCompiler;
use Atom\Database\Sql\Compiler\QueryCompilerInterface;
use Atom\Database\Sql\Query;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoParameterOverrideTest extends TestCase
{
    /** @return iterable<string, array{string, QueryCompilerInterface}> */
    public static function databases(): iterable
    {
        yield "MySQL" => ["mysql:host=127.0.0.1;port=13306;dbname=atom_orm_test;charset=utf8mb4", new MySqlCompiler()];
        yield "PostgreSQL" => ["pgsql:host=127.0.0.1;port=15432;dbname=atom_orm_test;connect_timeout=5", new PostgresCompiler()];
    }

    #[DataProvider("databases")]
    public function testExecutionParametersOverrideQueryAndCommandBindings(
        string $dsn,
        QueryCompilerInterface $compiler,
    ): void {
        $pdo = new PDO($dsn, "atom_test", "atom_test", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = new Db(new PdoConnection($pdo, $compiler));
        $db->execute("CREATE TEMPORARY TABLE parameter_override_fixture (id INTEGER PRIMARY KEY, team INTEGER, label VARCHAR(20))");
        $db->execute("INSERT INTO parameter_override_fixture VALUES (1, 7, 'original'), (2, 7, 'replacement'), (3, 8, 'other')");

        $query = Query::select("parameter_override_fixture")->columns("label")
            ->whereExp("id = :id", ["id" => 1])->where("team", 7);

        foreach ([$query, $db->compile($query)] as $input) {
            self::assertSame("replacement", $db->scalar($input, [":id" => 2]));
            self::assertSame("replacement", $db->scalar($input, ["id" => 2]));
            self::assertNull($db->first($input, ["id" => 3]));
            self::assertNull($db->first($input, ["id" => null]));
            self::assertSame("original", $db->scalar($input));
        }
    }
}
