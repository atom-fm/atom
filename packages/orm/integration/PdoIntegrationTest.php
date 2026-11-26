<?php

declare(strict_types=1);

namespace Atom\Tests\Database\Integration;

use Atom\Database\Db;
use Atom\Database\Exception\UniqueViolationException;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;
use Atom\Database\Orm\PdoConnection;
use Atom\Database\Sql\Compiler\MySqlCompiler;
use Atom\Database\Sql\Compiler\PostgresCompiler;
use Atom\Database\Sql\Compiler\QueryCompilerInterface;
use Atom\Database\Sql\Query;
use Atom\Database\Sql\UpsertQuery;
use Atom\Tests\Database\GeneratedKeyMappingAssertions;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../tests/GeneratedKeyMappingAssertions.php";

final class PdoIntegrationTest extends TestCase
{
    use GeneratedKeyMappingAssertions;

    private const string POSTGRES_DSN = "pgsql:host=127.0.0.1;port=15432;dbname=atom_orm_test;connect_timeout=5";

    /** @return iterable<string, array{string, QueryCompilerInterface}> */
    public static function databases(): iterable
    {
        yield "MySQL" => ["mysql:host=127.0.0.1;port=13306;dbname=atom_orm_test;charset=utf8mb4", new MySqlCompiler()];
        yield "PostgreSQL" => [self::POSTGRES_DSN, new PostgresCompiler()];
    }

    #[DataProvider("databases")]
    public function testExecutionParametersOverrideQueryAndCommandBindings(
        string $dsn,
        QueryCompilerInterface $compiler,
    ): void {
        $db = $this->database($dsn, $compiler);
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

    #[DataProvider("databases")]
    public function testBatchUpsertInsertsRowsAndUpdatesOnlySelectedColumns(string $dsn, QueryCompilerInterface $compiler): void
    {
        $db = $this->database($dsn, $compiler);
        $db->execute("CREATE TEMPORARY TABLE upsert_fixture (code VARCHAR(20) PRIMARY KEY, label VARCHAR(20), note VARCHAR(20))");
        $db->execute(new UpsertQuery("upsert_fixture", ["code" => "A", "label" => "Old", "note" => "Keep"], "code"));
        $db->execute(new UpsertQuery("upsert_fixture", [
            ["code" => "A", "label" => "Updated", "note" => "Ignored"],
            ["note" => null, "code" => "B", "label" => "O'Brien"],
        ], "code", ["label"]));

        self::assertSame([
            ["code" => "A", "label" => "Updated", "note" => "Keep"],
            ["code" => "B", "label" => "O'Brien", "note" => null],
        ], $db->all(Query::select("upsert_fixture")->orderBy("code")));
    }

    #[DataProvider("databases")]
    public function testDistinguishesUniqueViolationsFromOtherConstraintErrors(string $dsn, QueryCompilerInterface $compiler): void
    {
        $db = $this->database($dsn, $compiler);
        $db->execute("CREATE TEMPORARY TABLE constraint_fixture (id INTEGER PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE)");
        $db->execute(Query::insert("constraint_fixture")->values(["id" => 1, "code" => "taken"]));

        try {
            $db->execute(Query::insert("constraint_fixture")->values(["id" => 2, "code" => "taken"]));
            self::fail("Expected a unique violation for the duplicate code.");
        } catch (UniqueViolationException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(PDOException::class, $previous);
            self::assertSame($previous->errorInfo, $exception->errorInfo);
        }

        try {
            $db->execute(Query::insert("constraint_fixture")->values(["id" => 3, "code" => null]));
            self::fail("Expected a NOT NULL violation.");
        } catch (PDOException $exception) {
            self::assertNotInstanceOf(UniqueViolationException::class, $exception);
        }

        self::assertSame(1, (int) $db->scalar(Query::select("constraint_fixture")->count()));
    }

    #[DataProvider("databases")]
    public function testTransactionRollsBackAConstraintFailureAndCanCommitAgain(string $dsn, QueryCompilerInterface $compiler): void
    {
        $db = $this->database($dsn, $compiler);
        $db->execute("CREATE TEMPORARY TABLE transaction_fixture (code VARCHAR(20) PRIMARY KEY)");
        $db->execute(Query::insert("transaction_fixture")->values(["code" => "existing"]));

        try {
            $db->transaction(static function (Db $transaction): void {
                $transaction->execute(Query::insert("transaction_fixture")->values(["code" => "rolled back"]));
                $transaction->execute(Query::insert("transaction_fixture")->values(["code" => "existing"]));
            });
            self::fail("Expected the duplicate key to roll back the transaction.");
        } catch (UniqueViolationException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        $rows = Query::select("transaction_fixture")->orderBy("code");
        self::assertFalse($db->connection()->pdo()->inTransaction());
        self::assertSame([["code" => "existing"]], $db->all($rows));

        $result = $db->transaction(static function (Db $transaction): string {
            $transaction->execute(Query::insert("transaction_fixture")->values(["code" => "committed"]));
            return "done";
        });

        self::assertSame("done", $result);
        self::assertFalse($db->connection()->pdo()->inTransaction());
        self::assertSame([["code" => "committed"], ["code" => "existing"]], $db->all($rows));
    }

    #[DataProvider("databases")]
    public function testSavesAModelWithOnlyAGeneratedPrimaryKey(string $dsn, QueryCompilerInterface $compiler): void
    {
        $db = $this->database($dsn, $compiler);
        $identity = $compiler instanceof MySqlCompiler ? "AUTO_INCREMENT" : "GENERATED ALWAYS AS IDENTITY";
        $db->execute("CREATE TEMPORARY TABLE default_only_fixture (id INTEGER {$identity} PRIMARY KEY)");

        $model = new DefaultOnlyFixture();
        self::assertSame(1, $db->save($model));
        self::assertSame(1, $model->id);
        self::assertSame(1, (int) $db->scalar(Query::select("default_only_fixture")->columns("id")));
    }

    #[DataProvider("databases")]
    public function testFailedInsertLeavesGeneratedKeyUnsetAndCanBeRetried(string $dsn, QueryCompilerInterface $compiler): void
    {
        $db = $this->database($dsn, $compiler);
        $identity = $compiler instanceof MySqlCompiler ? "AUTO_INCREMENT" : "GENERATED ALWAYS AS IDENTITY";
        $db->execute("CREATE TEMPORARY TABLE insert_retry_fixture (id INTEGER {$identity} PRIMARY KEY, label VARCHAR(20) NOT NULL UNIQUE)");
        $db->execute(Query::insert("insert_retry_fixture")->values(["label" => "taken"]));

        $model = new InsertRetryFixture();
        $model->label = "taken";
        try {
            $db->save($model);
            self::fail("Expected the duplicate label to fail.");
        } catch (UniqueViolationException) {
            self::assertNull($model->id);
        }

        $model->label = "corrected";
        self::assertSame(1, $db->save($model));
        self::assertNotNull($model->id);
        self::assertSame("corrected", $db->scalar(Query::select("insert_retry_fixture")->columns("label")->where("id", $model->id)));
        self::assertSame(2, (int) $db->scalar(Query::select("insert_retry_fixture")->count()));
    }

    #[DataProvider("databases")]
    public function testGeneratedKeysRespectMappedColumnAndPropertyTypes(string $dsn, QueryCompilerInterface $compiler): void
    {
        $db = $this->database($dsn, $compiler);
        $keyDefinition = $compiler instanceof MySqlCompiler
            ? '`GeneratedKey` INTEGER AUTO_INCREMENT'
            : '"GeneratedKey" INTEGER GENERATED ALWAYS AS IDENTITY';
        $db->execute("CREATE TEMPORARY TABLE key_mapping_fixture ({$keyDefinition} PRIMARY KEY, label VARCHAR(20))");

        $this->assertGeneratedKeysMatchMappedProperties($db);
    }

    public function testPostgresInsertReturnsTheEntityKeyWhenATriggerGeneratesAnotherKey(): void
    {
        $db = $this->database(self::POSTGRES_DSN, new PostgresCompiler());
        $db->connection()->pdo()->exec(<<<'SQL'
            CREATE TEMPORARY TABLE generated_key_fixture (
                "ItemId" INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY, label VARCHAR(20)
            );
            CREATE TEMPORARY TABLE generated_key_audit (
                id INTEGER GENERATED ALWAYS AS IDENTITY (START WITH 100) PRIMARY KEY
            );
            CREATE FUNCTION pg_temp.log_generated_key() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO generated_key_audit DEFAULT VALUES;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER log_generated_key AFTER INSERT ON generated_key_fixture
                FOR EACH ROW EXECUTE FUNCTION pg_temp.log_generated_key();
            SQL);

        $entity = new GeneratedKeyFixture();
        $entity->label = "inserted";
        self::assertSame(1, $db->save($entity));
        self::assertSame(100, (int) $db->scalar(Query::select("generated_key_audit")->columns("id")));
        self::assertSame(1, $entity->id);
        self::assertSame("inserted", $db->scalar(
            Query::select("generated_key_fixture")->columns("label")->where("ItemId", $entity->id)
        ));
    }

    private function database(string $dsn, QueryCompilerInterface $compiler): Db
    {
        $pdo = new PDO($dsn, "atom_test", "atom_test", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return new Db(new PdoConnection($pdo, $compiler));
    }
}

#[Table("generated_key_fixture")]
final class GeneratedKeyFixture
{
    #[PrimaryKey("ItemId")]
    public ?int $id = null;

    #[Column]
    public string $label;
}

#[Table("default_only_fixture")]
final class DefaultOnlyFixture extends Model
{
    #[PrimaryKey]
    public ?int $id = null;
}

#[Table("insert_retry_fixture")]
final class InsertRetryFixture extends Model
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Column]
    public string $label;
}
