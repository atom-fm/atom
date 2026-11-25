<?php

declare(strict_types=1);

namespace Atom\Tests\Database;

use Atom\Database\Db;
use Atom\Database\Exception\UniqueViolationException;
use Atom\Database\Facades\Db as DbFacade;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\BelongsTo;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\HasMany;
use Atom\Database\Orm\Attributes\Projection;
use Atom\Database\Orm\Attributes\HasOne;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;
use Atom\Database\Orm\ColumnMetadata;
use Atom\Database\Orm\ColumnValueProviderInterface;
use Atom\Database\Orm\EntityMetadata;
use Atom\Database\Orm\HydrationAwareInterface;
use Atom\Database\Orm\PdoConnection;
use Atom\Database\Orm\Provider\NowProvider;
use Atom\Database\Sql\Compiler\SqliteCompiler;
use Atom\Database\Sql\Op;
use Atom\Database\Sql\Query;
use Atom\Database\Sql\WhereGroup;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase
{
    public function testDatabaseConnectionDistinguishesUniqueViolationsFromOtherConstraints(): void
    {
        $connection = self::memory();
        $connection->execute("CREATE TABLE members (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)");
        $connection->execute("INSERT INTO members VALUES (1, 'Pikachu')");

        foreach ([
            "INSERT INTO members VALUES (2, 'Pikachu')" => 2067,
            "INSERT INTO members VALUES (1, 'Eevee')" => 1555,
        ] as $sql => $code) {
            try {
                $connection->execute($sql);
                $this->fail("Expected a unique constraint violation.");
            } catch (UniqueViolationException $exception) {
                $this->assertInstanceOf(\PDOException::class, $exception->getPrevious());
                $this->assertSame($code, $exception->errorInfo[1]);
            }
        }

        try {
            $connection->execute("INSERT INTO members VALUES (2, NULL)");
            $this->fail("Expected a NOT NULL constraint violation.");
        } catch (\PDOException $exception) {
            $this->assertNotInstanceOf(UniqueViolationException::class, $exception);
            $this->assertSame(1299, $exception->errorInfo[1]);
        }
    }

    public function testDbServiceExecutesQueries(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

        $db->execute(Query::insert("users")->values(["name" => "Edin"]));

        $this->assertSame(["name" => "Edin"], $db->first(Query::select("users")->columns("name")));
        $this->assertSame(1, (int) $db->scalar(Query::select("users")->count()));
    }

    public function testDbServiceExecutesRawSqlWithParameters(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

        $db->execute(
            "INSERT INTO users (name) VALUES (:name)",
            [":name" => "Edin"]
        );

        $this->assertSame("Edin", $db->scalar(
            "SELECT name FROM users WHERE name = :name",
            [":name" => "Edin"]
        ));
    }

    public function testNestedQueriesPreserveCallerAndGeneratedBindingsAcrossScopes(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, team INTEGER)");
        $db->execute("INSERT INTO users (id, team) VALUES (1, 2), (2, 2), (3, 9)");

        $teamMember = Query::select("users")->columns("id")->where("team", 9);
        $members = Query::select("users")->columns("id")->whereExp("id <= :p2", ["p2" => 1]);
        $query = Query::select("users")->columns("id")->subqueryColumn($teamMember, "other_member")
            ->whereExp("id = :p1", [":p1" => 1])->where("team", 2);
        $query->getWhere()->whereInSubquery("id", $members);

        $this->assertSame([["id" => 1, "other_member" => 3]], $db->all($query));
    }

    public function testUpdatePreservesGeneratedAssignmentAndCallerFilter(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, score INTEGER)");
        $db->execute("INSERT INTO users (id, score) VALUES (1, 10), (2, 20)");
        $query = Query::update("users")->set(["score" => 2])->whereExp("id = :p1", ["p1" => 1]);

        $this->assertSame(1, $db->execute($query));
        $this->assertSame([2, 20], $db->select("users")->orderBy("id")->pluck("score"));
    }

    public function testDbCanHydrateRowsAsEntities(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->execute(Query::insert("users")->values(["name" => "Edin"]));

        $user = $db->firstAs(DbHydratedUser::class, Query::select("users")->columns("id", "name"));

        $this->assertInstanceOf(DbHydratedUser::class, $user);
        $this->assertSame(1, $user->id);
        $this->assertSame("Edin", $user->name);
        $this->assertSame("Edin", $user->originalName);
    }

    public function testDbSelectCanReturnRows(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, active INTEGER)");
        $db->execute(Query::insert("users")->values(["name" => "Edin", "active" => true]));

        $rows = $db
            ->select("users")
            ->columns("id", "name")
            ->where("active", true)
            ->all();

        $this->assertSame([["id" => 1, "name" => "Edin"]], $rows);
    }

    public function testDbSelectCanReturnEntities(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->execute(Query::insert("users")->values(["name" => "Edin"]));

        $user = $db
            ->select(DbHydratedUser::class)
            ->where("id", 1)
            ->first();

        $this->assertInstanceOf(DbHydratedUser::class, $user);
        $this->assertSame(1, $user->id);
        $this->assertSame("Edin", $user->name);
    }

    public function testDbSelectCanReturnScalarAndTotal(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->execute(Query::insert("users")->values(["name" => "Edin"]));
        $db->execute(Query::insert("users")->values(["name" => "Amar"]));

        $firstName = $db->select("users")->columns("name")->orderBy("id")->scalar();
        $total = $db->select("users")->columns("id", "name")->total();

        $this->assertSame("Edin", $firstName);
        $this->assertSame(2, $total);
    }

    public function testContainsMatchesLiteralTextInAColumn(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $match = "Before !%_' after";
        foreach ([$match, "Before !ABCx' after", "Before %_' after"] as $name) {
            $db->execute(Query::insert("users")->values(["name" => $name]));
        }

        $query = $db->select(DbHydratedUser::class)->where("name", Op::contains("!%_'"));
        $this->assertSame(1, $query->total());
        $user = $query->first();
        $this->assertInstanceOf(DbHydratedUser::class, $user);
        $this->assertSame($match, $user->name);
        $this->assertSame(3, $db->select("users")->where("name", Op::like("Before % after"))->total());
    }

    public function testWhereFromUntilIncludesTheStartAndExcludesTheEnd(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT)");
        $db->execute("INSERT INTO users VALUES
            (1, 'Before', '2026-01-09 23:59:59.999999'), (2, 'Start', '2026-01-10 00:00:00.000000'),
            (3, 'Inside', '2026-01-10 23:59:59.999999'), (4, 'End', '2026-01-11 00:00:00.000000')");

        $from = "2026-01-10 00:00:00.000000";
        $until = "2026-01-11 00:00:00.000000";
        $query = DbModelUser::query("u")->whereFromUntil("u.created_at", $from, $until);
        $this->assertSame([$from, $until], array_values($db->compile($query->query())->parameters));
        $this->assertSame([2, 3], $query->orderBy("u.id")->pluck("u.id"));
        $this->assertSame(0, DbModelUser::query()->whereFromUntil("created_at", $from, $from)->total());
    }

    public function testWhereAnyGroupsColumnsAndPreservesOperatorBindings(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE contacts (id INTEGER PRIMARY KEY, name TEXT, email TEXT, active INTEGER)");
        $db->execute("INSERT INTO contacts VALUES
            (1, 'A%_', NULL, 1), (2, NULL, '%_@example.com', 1),
            (3, 'A%_', NULL, 0), (4, NULL, '%_@example.com', 0), (5, 'ABC', NULL, 1)");

        $ids = $db->select("contacts c")
            ->whereAny(["c.name", "c.email"], Op::contains("%_"))
            ->where("c.active", true)
            ->orderBy("c.id")
            ->pluck("c.id");

        $this->assertSame([1, 2], $ids);
        $this->expectException(\InvalidArgumentException::class);
        $db->select("contacts")->whereAny([], "A");
    }

    public function testWhereContainsAnySkipsOnlyEmptyTermsAndMatchesLiteralTextAcrossColumns(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, active INTEGER)");
        $db->execute("INSERT INTO users VALUES
            (1, 'A!%_0', NULL, 1), (2, NULL, ' !%_ ', 1), (3, 'A!%_0', NULL, 0),
            (4, 'A!xx0', NULL, 1), (5, NULL, NULL, 1)");

        $columns = ["u.name", "u.email"];
        $query = DbModelUser::query("u")->where("u.active", true);
        $before = $db->compile($query->query());
        $this->assertSame($query, $query->whereContainsAny($columns, ""));
        $this->assertEquals($before, $db->compile($query->query()));
        $this->assertSame(4, $query->total());

        $matches = static fn (string $term) => DbModelUser::query("u")
            ->whereContainsAny($columns, $term)->where("u.active", true)->orderBy("u.id")->pluck("u.id");
        $this->assertSame([1, 2], $matches("!%_"));
        $this->assertSame([1, 4], $matches("0"));
        $this->assertSame([2], $matches(" "));

        $this->expectException(\InvalidArgumentException::class);
        $query->whereContainsAny([], "0");
    }

    public function testExistsChecksWhetherTheCurrentQueryReturnsARow(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute(Query::insert("users")->values(["name" => "Edin"]));

        $query = $db->select(DbHydratedUser::class)->where("name", "Edin");
        $this->assertTrue($query->exists());
        $this->assertSame("Edin", $query->first()->name);
        $this->assertFalse($query->limit(1)->offset(1)->exists());
        $this->assertFalse($db->select("users")->where("name", "Missing")->exists());
    }

    public function testPaginateClampsPagesAndKeepsTheOriginalQueryReusable(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        foreach (["Ava", "Bea", "Cole", "Skip"] as $name) {
            $db->execute(Query::insert("users")->values(["name" => $name]));
        }

        $query = $db->select("users")->columns("name")->where("name", Op::ne("Skip"))
            ->orderByDesc("id")->limit(1)->offset(1);
        $lastPage = $query->paginate(999, 2);
        $this->assertSame(3, $lastPage->totalCount());
        $this->assertSame(2, $lastPage->page());
        $this->assertSame(2, $lastPage->pageSize());
        $this->assertSame([["name" => "Ava"]], $lastPage->toArray());

        $firstPage = $query->paginate(0, 2);
        $this->assertSame(1, $firstPage->page());
        $this->assertSame([["name" => "Cole"], ["name" => "Bea"]], $firstPage->toArray());
        $this->assertSame([["name" => "Bea"]], $query->all());

        $empty = $db->select("users")->where("name", "Missing")->paginate(999, 2);
        $this->assertSame(0, $empty->totalCount());
        $this->assertSame(1, $empty->page());
        $this->assertSame([], $empty->toArray());
    }

    public function testCanProjectWildcardColumnsAlongsideAScalarColumn(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT)");
        $db->execute(Query::insert("articles")->values(["id" => 1, "title" => "News"]));

        foreach (["*", "a.*"] as $wildcard) {
            $rows = $db->select("articles a")->columns($wildcard)
                ->scalarColumn("LENGTH(a.title)", "title_length")
                ->all();

            $this->assertSame([["id" => 1, "title" => "News", "title_length" => 4]], $rows);
        }
    }

    public function testPaginateKeepsScalarColumnsOnPageRows(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY); CREATE TABLE comments (article_id INTEGER, visible INTEGER)");
        $db->execute(Query::insert("articles")->values(["id" => 1]));
        $db->execute(Query::insert("articles")->values(["id" => 2]));
        $db->execute(Query::insert("comments")->values(["article_id" => 2, "visible" => 1]));
        $db->execute(Query::insert("comments")->values(["article_id" => 2, "visible" => 0]));

        $page = $db->select("articles a")->columns("a.id")
            ->scalarColumn(
                "(SELECT COUNT(*) FROM comments c WHERE c.article_id = a.id AND c.visible = :visible)",
                "comment_count",
                ["visible" => 1]
            )
            ->where("a.id", Op::gte(1))
            ->orderBy("a.id")
            ->paginate(2, 1);

        $this->assertSame(2, $page->totalCount());
        $this->assertSame([["id" => 2, "comment_count" => 1]], $page->toArray());
    }

    public function testModelQueriesOrderByMultipleColumnsInEitherDirection(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, sort_order INTEGER)");
        $db->execute("INSERT INTO users VALUES (1, 'Beta', 1), (2, 'Zulu', 0), (3, 'Alpha', 1), (4, 'Alpha', 1)");

        $this->assertSame([2, 3, 4, 1], DbModelUser::query("u")->orderBy("u.sort_order", "u.name", "u.id")->pluck("u.id"));
        $this->assertSame([1, 4, 3, 2], DbModelUser::query("u")->orderByDesc("u.sort_order", "u.name", "u.id")->pluck("u.id"));
    }

    public function testPaginateOrdersByAnExpressionWithBoundParameters(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE tickets (id INTEGER PRIMARY KEY, status TEXT, priority INTEGER)");
        foreach ([["closed", 5], ["open", 2], ["open", 1], ["open", 2]] as [$status, $priority]) {
            $db->execute(Query::insert("tickets")->values(["status" => $status, "priority" => $priority]));
        }

        $query = $db->select("tickets")->columns("id")
            ->orderByExp("(status = :preferred)", ["preferred" => "open"], descending: true)
            ->orderByDesc("priority")
            ->orderBy("id");
        $first = $query->paginate(1, 2);
        $this->assertSame(4, $first->totalCount());
        $this->assertSame([["id" => 2], ["id" => 4]], $first->toArray());
        $this->assertSame([["id" => 3], ["id" => 1]], $query->paginate(2, 2)->toArray());
    }

    public function testPaginateCountsGroupedAndAggregateResultRows(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)");
        foreach (["staff", "staff", "member", "member", "guest"] as $role) {
            $db->execute(Query::insert("users")->values(["role" => $role]));
        }

        $groups = $db->select("users")->columns("role")->count("id", "user_count")
            ->groupBy("role")->having("user_count", Op::gte(2))->orderBy("role")->paginate(2, 1);
        $this->assertSame(2, $groups->totalCount());
        $this->assertSame([["role" => "staff", "user_count" => 2]], $groups->toArray());

        $aggregate = $db->select("users")->count("id", "user_count")->where("role", "missing")->paginate(5, 1);
        $this->assertSame(1, $aggregate->totalCount());
        $this->assertSame(1, $aggregate->page());
        $this->assertSame([["user_count" => 0]], $aggregate->toArray());
    }

    public function testPaginateRejectsInvalidPageSizeBeforeQuerying(): void
    {
        $db = new Db(self::memory());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Page size must be greater than zero.");
        $db->select("users")->paginate(1, 0);
    }

    public function testDbCanInsertEntityAndFillAutoIncrementPrimaryKey(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

        $user = new DbHydratedUser();
        $user->name = "Edin";

        $affected = $db->insert($user);

        $this->assertSame(1, $affected);
        $this->assertSame(1, $user->id);
        $this->assertSame("Edin", $db->select(DbHydratedUser::class)->where("id", 1)->first()->name);
    }

    public function testDbCanUpdateEntity(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->execute(Query::insert("users")->values(["name" => "Edin"]));

        $user = $db->select(DbHydratedUser::class)->where("id", 1)->first();
        $user->name = "Atom";

        $affected = $db->update($user);

        $this->assertSame(1, $affected);
        $this->assertSame("Atom", $db->select(DbHydratedUser::class)->where("id", 1)->first()->name);
    }

    public function testDbCanSaveNewAndExistingEntity(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

        $user = new DbHydratedUser();
        $user->name = "Edin";

        $db->save($user);
        $user->name = "Atom";
        $db->save($user);

        $this->assertSame(1, $user->id);
        $this->assertSame("Atom", $db->select(DbHydratedUser::class)->where("id", 1)->first()->name);
        $this->assertSame(1, $db->select(DbHydratedUser::class)->total());
    }

    public function testDbUsesColumnValueProvidersOnInsertAndUpdate(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec(
            "CREATE TABLE timestamped_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, created_at TEXT, updated_at TEXT)"
        );

        $post = new DbTimestampedPost();
        $post->title = "First";

        $db->insert($post);
        $createdAt = $post->createdAt;
        $updatedAt = $post->updatedAt;

        $this->assertInstanceOf(DateTimeImmutable::class, $createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $updatedAt);

        $post->title = "Second";
        $db->update($post);

        $this->assertSame($createdAt, $post->createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $post->updatedAt);
    }

    public function testDbCanDeleteEntity(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->execute(Query::insert("users")->values(["name" => "Edin"]));

        $user = $db->select(DbHydratedUser::class)->where("id", 1)->first();

        $affected = $db->delete($user);

        $this->assertSame(1, $affected);
        $this->assertNull($db->select(DbHydratedUser::class)->where("id", 1)->first());
    }

    public function testModelSavePreservesUnselectedDefaultsAndConcurrentEdits(): void
    {
        $db = new Db(self::memory());
        DbTrackedUser::useDb($db);
        $db->execute("CREATE TABLE tracked_users (id INTEGER PRIMARY KEY, name TEXT, note TEXT, private_label TEXT, optional_code TEXT)");
        $db->execute("INSERT INTO tracked_users VALUES (1, 'Alice', 'original', 'stored label', 'stored code')");
        $user = DbTrackedUser::find(1);
        $db->execute("UPDATE tracked_users SET note = 'other writer' WHERE id = 1");
        $user->name = "Updated";

        $this->assertSame(1, $user->save());
        $this->assertSame(["name" => "Updated", "note" => "other writer", "private_label" => "stored label", "optional_code" => "stored code"],
            $db->first("SELECT name, note, private_label, optional_code FROM tracked_users"));
        $this->assertSame(["name" => "Updated", "note" => "original"], $user->originalValues());
        $this->assertSame(0, $user->save());

        $user->privateLabel = "replacement";
        $this->assertSame(["private_label" => "replacement"], $user->changedValues());
        $this->assertSame(1, $db->update($user));
        $this->assertSame("replacement", $db->scalar("SELECT private_label FROM tracked_users"));

        $withLabel = DbTrackedUser::query()->columns("private_label")->find(1);
        $this->assertSame("replacement", $withLabel->originalValues()["private_label"]);
        $withLabel->privateLabel = "";
        $this->assertSame(1, $withLabel->save());
        $this->assertSame("", $db->scalar("SELECT private_label FROM tracked_users"));
    }

    public function testModelSaveDistinguishesAnUninitializedColumnFromAnExplicitNull(): void
    {
        $db = new Db(self::memory());
        DbTrackedUser::useDb($db);
        $db->execute("CREATE TABLE tracked_users (id INTEGER PRIMARY KEY, name TEXT, note TEXT, private_label TEXT, optional_code TEXT)");
        $db->execute("INSERT INTO tracked_users VALUES (1, 'Alice', NULL, 'stored label', 'stored code')");
        $user = DbTrackedUser::find(1);
        $user->optionalCode = null;

        $this->assertSame(["optional_code" => null], $user->changedValues());
        $this->assertSame(1, $db->save($user));
        $this->assertNull($db->scalar("SELECT optional_code FROM tracked_users"));
        $this->assertArrayHasKey("optional_code", $user->originalValues());
        $this->assertSame([], $user->changedValues());
    }

    public function testFailedModelUpdateKeepsTheOriginalValuesForRetry(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT UNIQUE)");
        $db->execute("INSERT INTO users VALUES (1, 'Alice'), (2, 'Bob')");
        $user = DbModelUser::find(1);
        $user->name = "Bob";
        try {
            $user->save();
            $this->fail("Expected duplicate name to fail.");
        } catch (\PDOException) {
            $this->assertSame(["name" => "Alice"], $user->originalValues());
            $this->assertSame(["name" => "Bob"], $user->changedValues());
        }
        $db->execute("DELETE FROM users WHERE id = 2");
        $this->assertSame(1, $user->save());
        $this->assertSame(0, $user->save());
    }

    public function testModelBaseCanQueryFindSaveAndDelete(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        DbModelUser::useDb($db);

        $user = new DbModelUser();
        $user->name = "Edin";

        $user->save();

        $this->assertSame(1, $user->id);
        $this->assertSame(1, DbModelUser::count());
        $this->assertSame("Edin", DbModelUser::find(1)?->name);
        $this->assertSame("Edin", DbModelUser::query()->where("id", 1)->first()?->name);

        $user->name = "Atom";
        $user->save();

        $this->assertSame("Atom", DbModelUser::find(1)?->name);

        $user->delete();

        $this->assertNull(DbModelUser::find(1));
    }

    public function testFacadeTransactionsFollowTheModelDatabaseAndRollBackModelWrites(): void
    {
        $first = new Db(self::memory());
        Model::useDb($first);
        $this->assertSame($first, DbFacade::transaction(static fn (Db $db): Db => $db));

        $second = new Db(self::memory());
        DbModelUser::useDb($second);
        $second->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $failure = new \RuntimeException("Cancel this write");

        try {
            DbFacade::transaction(function (Db $db) use ($second, $failure): void {
                $this->assertSame($second, $db);
                $user = new DbModelUser();
                $user->name = "Temporary";
                $user->save();
                $this->assertSame(1, DbModelUser::count());
                throw $failure;
            });
            $this->fail("The callback exception should reach the caller.");
        } catch (\RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame(0, DbModelUser::count());
    }

    public function testNamedCountsKeepIndependentBindingsAndLeaveTheInputQueriesReusable(): void
    {
        $db = new Db(self::memory());
        Model::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users VALUES (1, 'Atom'), (2, 'Atom'), (3, ':name')");
        $named = DbModelUser::query()->whereExp("name = :name", ["name" => "Atom"]);
        $other = DbModelUser::query()->whereExp("name = :name OR name = ':name'", ["name" => "absent"]);
        $page = DbModelUser::query()->orderBy("id")->limit(1)->offset(1);
        $groups = DbModelUser::query()->asRows()->columns("name")->count("*", "n")->groupBy("name")->having("n", Op::gt(1));

        $this->assertSame([
            "all" => 3, "named" => 2, "other" => 1, "groups" => 1, "page" => 1, "missing" => 0,
        ], DbFacade::counts([
            "all" => DbModelUser::query(), "named" => $named, "other" => $other,
            "groups" => $groups, "page" => $page, "missing" => DbModelUser::query()->where("id", -1),
        ]));
        $this->assertSame([2], $page->pluck("id"));
        $group = $groups->first();
        $this->assertSame("Atom", $group->name);
        $this->assertSame(2, $group->n);
        $this->assertSame([], DbFacade::counts([]));
    }

    public function testOffsetWithoutLimitReturnsAllRemainingRows(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY)");
        $db->execute("INSERT INTO users VALUES (1), (2), (3)");
        $query = $db->select("users")->orderByDesc("id");

        $this->assertSame([["id" => 2], ["id" => 1]], $query->offset(1)->all());
        $this->assertSame([], $query->offset(4)->all());
        $this->assertSame([3, 2, 1], $query->offset(0)->pluck("id"));
    }

    public function testCountsRespectOffsetWithoutLimit(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY)");
        $db->execute("INSERT INTO users VALUES (1), (2), (3)");
        $remaining = $db->select("users")->where("id", Op::gt(1))->orderBy("id")->offset(1);

        $this->assertSame(["remaining" => 1, "beyond" => 0, "all" => 3], $db->counts([
            "remaining" => $remaining,
            "beyond" => $db->select("users")->offset(4),
            "all" => $db->select("users")->offset(0),
        ]));
        $this->assertSame([3], $remaining->pluck("id"));
    }

    public function testNamedCountsRejectQueriesOnADifferentConnection(): void
    {
        $first = new Db(self::memory());
        $second = new Db(self::memory());
        Model::useDb($second);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("same database connection");
        DbFacade::counts(["users" => $first->select("users")]);
    }

    public function testSubqueryColumnsKeepTheirOwnSelectionFiltersAndBindings(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users VALUES (1, 'First'), (2, 'Second'), (3, 'Second')");

        $count = DbModelUser::query("u")->where("u.name", "Second")->count();
        $name = DbModelUser::query("u")->columns("u.name")->whereExp("u.id = outer_user.id")->limit(1);
        $outer = DbModelUser::query("outer_user")->columns("outer_user.id")
            ->subqueryColumn($count, "matches")
            ->subqueryColumn($name, "current_name")
            ->where("outer_user.id", 1)->asRows();
        $count->where("u.id", 99);
        $outer->subqueryColumn($count, "no_matches");
        $outer->subqueryColumn($name->where("u.id", 99), "missing_name");

        $page = $outer->paginate(1, 10);
        $this->assertSame(1, $page->totalCount());
        $this->assertSame([
            "id" => 1, "matches" => 2, "current_name" => "First", "no_matches" => 0, "missing_name" => null,
        ], get_object_vars($page->first()));
    }

    public function testSubqueryColumnRequiresOneExplicitColumn(): void
    {
        DbModelUser::useDb(new Db(self::memory()));
        $outer = DbModelUser::query();
        foreach ([[], ["users.*"], ["id", "name"]] as $columns) {
            try {
                $outer->subqueryColumn(DbModelUser::query()->columns(...$columns), "result");
                $this->fail("Expected a scalar column selection error.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString("one explicitly selected column", $exception->getMessage());
            }
        }
    }

    public function testSubqueryColumnRequiresTheSameConnection(): void
    {
        $first = new Db(self::memory());
        $second = new Db(self::memory());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("same database connection");
        $first->select("users")->subqueryColumn($second->select("users")->count(), "matches");
    }

    public function testRowQueryCanPaginateAProjectionWithoutChangingTheSource(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO users VALUES (1, 'First'), (2, 'Second')");
        DbModelUser::useDb($db);

        $query = DbModelUser::query("u")->asRows()->columns("u.name display_name")->orderBy("u.id");
        $page = $query->asProjection(DbUserNameProjection::class)->paginate(2, 1);
        $row = $page->first();
        $this->assertInstanceOf(DbUserNameProjection::class, $row);
        $this->assertSame("Second", $row->name);
        $this->assertSame(2, $page->totalCount());
        $this->assertSame("First", $query->first()->display_name);

        $this->expectException(\InvalidArgumentException::class);
        $query->asProjection(DbModelUser::class);
    }

    public function testOneModelQueryCanReturnModelsRowsOrTypedProjections(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users VALUES (1, 'First'), (2, 'Second')");
        $query = DbModelUser::query("u")->columns("u.name display_name")->orderBy("u.id");

        $rows = $query->asRows();
        $arrays = $query->asArrays();
        $this->assertSame([["display_name" => "First"], ["display_name" => "Second"]], $arrays->all());
        $this->assertSame(["display_name" => "First"], $arrays->find(1));
        $this->assertSame(["display_name" => "Second"], $arrays->paginate(2, 1)->first());
        $this->assertNull($arrays->where("u.id", 99)->first());
        $page = $rows->paginate(2, 1);
        $this->assertSame(2, $page->totalCount());
        $this->assertInstanceOf(\stdClass::class, $page->first());
        $this->assertSame(["display_name" => "Second"], get_object_vars($page->first()));
        $this->assertSame(["First", "Second"], array_column($rows->all(), "display_name"));
        $this->assertNull($rows->where("u.id", 99)->first());

        $projected = $query->asProjection(DbUserNameProjection::class)->first();
        $this->assertInstanceOf(DbUserNameProjection::class, $projected);
        $this->assertSame("First", $projected->name);
        $user = $query->first();
        $this->assertInstanceOf(DbModelUser::class, $user);
        $this->assertSame(1, $user->id);
        $this->assertSame("First", $user->name);
    }

    public function testModelQueryPaginatesAliasedArrayResultsWithScalarColumns(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO users VALUES (1, 'First'), (2, 'Second')");
        DbModelUser::useDb($db);

        $page = DbModelUser::query("u")->asArrays()->columns("u.name")
            ->scalarColumn("u.id * 2", "doubled")
            ->orderBy("u.id")
            ->paginate(2, 1);

        $this->assertSame(2, $page->totalCount());
        $this->assertSame([["name" => "Second", "doubled" => 4]], $page->toArray());
    }

    public function testRowResultsUseSourceRelationsAndKeepEagerLoadingOnTheModel(): void
    {
        $db = new Db(self::memory());
        DbCategorizedArticle::useDb($db);
        $db->execute("CREATE TABLE articles (article_key TEXT PRIMARY KEY, category_id INTEGER, fallback_id INTEGER)");
        $db->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO articles VALUES ('article', 1, 1)");
        $db->execute("INSERT INTO categories VALUES (1, 'Tech')");

        $query = DbCategorizedArticle::query("a")->leftJoinRelation("category", "c")
            ->columns("c.name display_name");
        $row = $query->asRows()->first();
        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame(["display_name" => "Tech"], get_object_vars($row));
        $article = $query->with("category")->first();
        $this->assertSame("article", $article?->key);
        $this->assertSame("Tech", $article?->category?->name);
        $this->expectException(\InvalidArgumentException::class);
        $query->asRows();
    }

    public function testArrayResultsKeepSourceRelationsAndRejectEagerLoading(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('ABC')");
        $query = DbInvoice::query()->asArrays()->columns("invoice_no")->withCount("lines");
        $this->assertSame(["invoice_no" => "ABC", "lines_count" => 0], $query->find("ABC"));
        $this->expectException(\InvalidArgumentException::class);
        $query->with("lines");
    }

    public function testModelQueryCanPluckAnOrderedColumnWithoutChangingProjection(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO users VALUES (1, 'First'), (2, NULL), (3, 'Other')");
        DbModelUser::useDb($db);

        $query = DbModelUser::query("u")->asArrays()->columns("u.id")->where("u.id", Op::lt(3))->orderByDesc("u.id");
        $this->assertSame([null, "First"], $query->pluck("u.name"));
        $this->assertSame([2 => null, 1 => "First"], $query->pluck("u.name", "u.id"));
        $this->assertSame([["id" => 2], ["id" => 1]], $query->all());
        $this->assertSame([], DbModelUser::query()->where("id", 99)->pluck("name"));
        $this->assertSame([], DbModelUser::query()->where("id", 99)->pluck("name", "id"));

        $this->expectException(\InvalidArgumentException::class);
        $query->pluck("u.*");
    }

    public function testKeyedPluckDistinguishesJoinedColumnsAndKeepsTheLastValueForRepeatedKeys(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, manager_id INTEGER)");
        $db->execute("INSERT INTO users VALUES (1, 'Team', NULL), (2, 'First', 1), (3, 'Second', 1)");
        DbModelUser::useDb($db);

        $reports = DbModelUser::query("u")->join("users manager ON manager.id = u.manager_id")->orderBy("u.id");
        $this->assertSame([2 => 1, 3 => 1], $reports->pluck("manager.id", "u.id"));
        $this->assertSame(["Team" => "Second"], $reports->pluck("u.name", "manager.name"));

        $this->expectException(\InvalidArgumentException::class);
        $reports->pluck("u.name", "manager.*");
    }

    public function testModelQueryCanReadScopedAggregatesWithoutChangingProjection(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO users VALUES (1, 'Match'), (2, 'Match'), (3, 'Other')");
        DbModelUser::useDb($db);

        $query = DbModelUser::query("u")->asArrays()->columns("u.name")->where("u.name", "Match");
        $this->assertSame(2, $query->max("u.id"));
        $this->assertSame(3, $query->sum("u.id"));
        $this->assertSame([["name" => "Match"], ["name" => "Match"]], $query->all());
        $this->assertNull(DbModelUser::query()->where("name", "Missing")->max("id"));
        $this->assertNull(DbModelUser::query()->where("name", "Missing")->sum("id"));
    }

    public function testNextPositionUsesTheLargestPositionWithinTheQueryScope(): void
    {
        $db = new Db(self::memory());
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, sort_order INTEGER)");
        DbModelUser::useDb($db);
        $query = DbModelUser::query("u")->where("u.name", "Match");
        $this->assertSame(0, $query->nextPosition("u.sort_order"));

        $db->execute("INSERT INTO users (name, sort_order) VALUES ('Match', NULL), ('Other', 99)");
        $this->assertSame(0, $query->nextPosition("u.sort_order"));
        $db->execute("INSERT INTO users (name, sort_order) VALUES ('Match', 0)");
        $this->assertSame(1, $query->nextPosition("u.sort_order"));
        $db->execute("INSERT INTO users (name, sort_order) VALUES ('Match', 7)");
        $this->assertSame(8, $query->nextPosition("u.sort_order"));
        $this->assertSame(100, DbModelUser::query()->nextPosition("sort_order"));

        $db->execute("INSERT INTO users (name, sort_order) VALUES ('Match', :last)", ["last" => PHP_INT_MAX]);
        $this->expectException(\OverflowException::class);
        $query->nextPosition("u.sort_order");
    }

    public function testModelDeleteWhereUsesMappedTableAndCombinesFilters(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO users VALUES (1, 'Archive'), (2, 'Keep'), (3, 'Archive')");
        DbModelUser::useDb($db);

        $this->assertSame(1, DbModelUser::deleteWhere(["name" => "Archive", "id" => 3]));
        $this->assertSame(0, DbModelUser::deleteWhere(["name" => "Missing"]));
        $this->assertSame("Archive", DbModelUser::find(1)?->name);
        $this->assertSame("Keep", DbModelUser::find(2)?->name);
        $this->assertNull(DbModelUser::find(3));

        $this->expectException(\InvalidArgumentException::class);
        DbModelUser::deleteWhere([]);
    }

    public function testModelUpsertInsertsAndReplacesOnlySelectedColumns(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, note TEXT)");
        $this->assertSame(0, DbModelUser::upsert([], "id"));
        DbModelUser::upsert(["id" => 1, "name" => "Old", "note" => "Keep"], "id");
        $affected = DbModelUser::upsert([
            ["id" => 1, "name" => "Updated", "note" => "Ignored"],
            ["note" => null, "id" => 2, "name" => "O'Brien"],
        ], uniqueBy: "id", update: ["name"]);
        $this->assertSame(2, $affected);
        $this->assertSame([
            ["id" => 1, "name" => "Updated", "note" => "Keep"],
            ["id" => 2, "name" => "O'Brien", "note" => null],
        ], DbModelUser::query()->asArrays()->orderBy("id")->all());

        DbModelUser::upsert(["id" => 1, "name" => "Default", "note" => null], "id");
        $this->assertSame(["id" => 1, "name" => "Default", "note" => null], DbModelUser::query()->asArrays()->find(1));
        $this->expectException(\PDOException::class);
        DbModelUser::upsert(["id" => 1, "name" => "Duplicate"], "id", []);
    }

    public function testModelUpsertRejectsInconsistentRowsBeforeWriting(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        try {
            DbModelUser::upsert([["id" => 1, "name" => "Valid"], ["id" => 2]], "id");
            $this->fail("Expected inconsistent upsert columns to be rejected.");
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, DbModelUser::count());
        }
    }

    public function testModelUpdateWhereUpdatesOnlyRowsMatchingAllFilters(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO users VALUES (1, 'First'), (2, 'First'), (3, 'Other')");
        DbModelUser::useDb($db);

        $this->assertSame(1, DbModelUser::updateWhere(["name" => "First", "id" => 2], ["name" => "Updated"]));
        $this->assertSame(0, DbModelUser::updateWhere(["id" => 99], ["name" => "Updated"]));
        $this->assertSame(["First", "Updated", "Other"], DbModelUser::query()->orderBy("id")->pluck("name"));

        $this->expectException(\InvalidArgumentException::class);
        DbModelUser::updateWhere([], ["name" => "Updated"]);
    }

    public function testUpdateByIdTargetsTheMappedKeyAndRejectsAnEmptyWrite(): void
    {
        $db = new Db(self::memory());
        Model::useDb($db);
        $db->execute("CREATE TABLE articles (article_key TEXT PRIMARY KEY, category_id INTEGER)");
        $db->execute("INSERT INTO articles VALUES ('A', 1), ('B', 2)");

        $this->assertSame(1, DbCategorizedArticle::updateById("A", ["category_id" => 3]));
        $this->assertSame(0, DbCategorizedArticle::updateById("missing", ["category_id" => 4]));
        $this->assertSame([3, 2], DbCategorizedArticle::query()->orderBy("article_key")->pluck("category_id"));
        $this->expectException(\InvalidArgumentException::class);
        DbCategorizedArticle::updateById("A", []);
    }

    public function testUpdateByIdAppliesUpdateProvidersLastAndAcceptsProviderOnlyUpdates(): void
    {
        $db = new Db(self::memory());
        Model::useDb($db);
        $db->execute("CREATE TABLE timestamped_posts (id INTEGER PRIMARY KEY, title TEXT, created_at TEXT, updated_at TEXT)");
        $db->execute("INSERT INTO timestamped_posts VALUES (0, 'Original', 'created', NULL)");
        $this->assertSame(1, DbTimestampedPost::updateById(0, []));
        $this->assertNotNull($db->scalar("SELECT updated_at FROM timestamped_posts"));
        $this->assertSame(1, DbTimestampedPost::updateById(0, ["title" => "Updated", "updated_at" => "ignored"]));
        $row = $db->first("SELECT title, created_at, updated_at FROM timestamped_posts");
        $this->assertSame("Updated", $row["title"]);
        $this->assertSame("created", $row["created_at"]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row["updated_at"]);
    }

    public function testUpdateByIdRejectsProvidersThatNeedAnEntityBeforeWriting(): void
    {
        $db = new Db(self::memory());
        Model::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users VALUES (1, 'Original')");
        try {
            DbUserWithNameProvider::updateById(1, ["name" => "Updated"]);
            $this->fail("Expected an entity-dependent provider to be rejected.");
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("Use save() on a loaded model", $exception->getMessage());
        }
        $this->assertSame("Original", $db->scalar("SELECT name FROM users"));
    }

    public function testModelKeyLookupsUseTheMappedKeyAndLoadRequestedRelations(): void
    {
        $db = new Db(self::memory());
        DbCategorizedArticle::useDb($db);
        $db->execute("CREATE TABLE articles (article_key TEXT PRIMARY KEY, category_id INTEGER, fallback_id INTEGER)");
        $db->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO articles VALUES ('a-42', 1, 2)");
        $db->execute("INSERT INTO categories VALUES (1, 'Tech'), (2, 'General')");

        $article = DbCategorizedArticle::find("a-42", "category", "fallback");
        $this->assertSame("Tech", $article?->category?->name);
        $this->assertSame("General", $article?->fallback?->name);
        $this->assertTrue(DbCategorizedArticle::existsById("a-42"));
        $this->assertFalse(DbCategorizedArticle::existsById("missing"));
        $this->assertNull(DbCategorizedArticle::find("missing", "category"));
    }

    public function testBuilderFindScopesAllAlternativesAndKeepsTheBuilderReusable(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A'), ('B'), ('C')");
        $db->execute("INSERT INTO invoice_lines VALUES ('B', 9)");
        $query = DbInvoice::query("i")->with("lines")
            ->where("i.invoice_no", "A")->orWhere("i.invoice_no", "B")->orderBy("i.invoice_no");
        $before = $db->compile($query->query());

        $invoice = $query->find("B");
        $this->assertInstanceOf(DbInvoice::class, $invoice);
        $this->assertSame("B", $invoice->number);
        $this->assertSame([9.0], array_column($invoice->lines, "amount"));
        $this->assertNull($query->find("C"));
        $this->assertNull($query->find("missing"));
        $this->assertSame("A", $query->find("A")?->number);
        $this->assertEquals($before, $db->compile($query->query()));
        $this->assertSame(["A", "B"], $query->pluck("i.invoice_no"));
    }

    public function testBuilderFindPreservesSelectedResultTypesOnAnAliasedJoin(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users VALUES (0, 'First'), (1, 'Second')");
        $query = DbModelUser::query("u")->join("users other ON other.id <> u.id")
            ->columns("other.name display_name");

        $this->assertSame(["display_name" => "Second"], $query->asArrays()->find(0));
        $row = $query->asRows()->find(1);
        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame(["display_name" => "First"], get_object_vars($row));
        $projection = $query->asProjection(DbUserNameProjection::class)->find(0);
        $this->assertInstanceOf(DbUserNameProjection::class, $projection);
        $this->assertSame("Second", $projection->name);
        $this->assertNull($query->asProjection(DbUserNameProjection::class)->find(2));
    }

    public function testExceptIdTreatsNullAsANoOpAndZeroAsAnId(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO users VALUES (0, 'Match'), (1, 'Match'), (2, 'Other')");

        $query = DbModelUser::query()->where("name", "Match")->orderBy("id");
        $before = $db->compile($query->query());
        $this->assertSame($query, $query->exceptId(null));
        $this->assertEquals($before, $db->compile($query->query()));
        $this->assertSame([0, 1], $query->pluck("id"));
        $this->assertSame([1], $query->exceptId(0)->pluck("id"));
    }

    public function testExceptIdUsesTheMappedSourceKeyOnAnAliasedJoin(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("INSERT INTO invoices VALUES ('A'), ('B')");
        $query = DbInvoice::query("i")->join("invoices other ON other.invoice_no <> i.invoice_no")
            ->columns("other.invoice_no")->asRows()->exceptId("A");
        $this->assertSame(["A"], array_column($query->all(), "invoice_no"));
    }

    public function testExceptIdRequiresASingleMappedKey(): void
    {
        DbInvoiceLine::useDb(new Db(self::memory()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must define a single primary key");
        DbInvoiceLine::query()->exceptId(1);
    }

    public function testAllIdsExistChecksDistinctIdsAcrossBatches(): void
    {
        $db = new Db(self::memory());
        DbModelUser::useDb($db);
        $this->assertTrue(DbModelUser::allIdsExist([]));

        $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("WITH RECURSIVE ids(id) AS (SELECT 1 UNION ALL SELECT id + 1 FROM ids WHERE id < 1001)
            INSERT INTO users (id) SELECT id FROM ids");

        $this->assertTrue(DbModelUser::allIdsExist([...range(1, 1001), 1, 501, '1001']));
        $this->assertTrue(DbModelUser::allIdsExist([1, "01"]));
        $this->assertFalse(DbModelUser::allIdsExist(range(1, 1002)));
        $this->assertFalse(DbModelUser::allIdsExist([null]));
    }

    public function testAllIdsExistUsesTheMappedStringKeyAndDatabaseComparison(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY COLLATE NOCASE)");
        $db->execute("INSERT INTO invoices VALUES ('Inv-A'), ('01'), ('1')");
        $this->assertTrue(DbInvoice::allIdsExist(["Inv-A", "INV-A", "01", "1"]));
        $this->assertFalse(DbInvoice::allIdsExist(["Inv-A", "missing"]));
        $db->execute("DELETE FROM invoices WHERE invoice_no = '01'");
        $this->assertFalse(DbInvoice::allIdsExist(["1", "01"]));
    }

    public function testDbSelectCanLoadBelongsToRelation(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->connection()->pdo()->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, category_id INTEGER)");

        $db->execute(Query::insert("categories")->values(["name" => "Tech"]));
        $db->execute(Query::insert("articles")->values(["title" => "Atom", "category_id" => 1]));

        $article = $db
            ->select(DbArticle::class)
            ->with("category")
            ->where("id", 1)
            ->first();

        $this->assertInstanceOf(DbArticle::class, $article);
        $this->assertInstanceOf(DbCategory::class, $article->category);
        $this->assertSame("Tech", $article->category->name);
    }

    public function testDbSelectLoadsBelongsToRelationForManyModelsInBatch(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->connection()->pdo()->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, category_id INTEGER)");

        $db->execute(Query::insert("categories")->values(["name" => "Tech"]));
        $db->execute(Query::insert("categories")->values(["name" => "Sports"]));
        $db->execute(Query::insert("articles")->values(["title" => "Atom", "category_id" => 1]));
        $db->execute(Query::insert("articles")->values(["title" => "BiH", "category_id" => 2]));

        $articles = $db
            ->select(DbArticle::class)
            ->with("category")
            ->orderBy("id")
            ->all();

        $this->assertCount(2, $articles);
        $this->assertSame("Tech", $articles[0]->category->name);
        $this->assertSame("Sports", $articles[1]->category->name);
    }

    public function testDbSelectCanLoadHasOneRelation(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $db->connection()->pdo()->exec("CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)");

        $db->execute(Query::insert("users")->values(["name" => "Edin"]));
        $db->execute(Query::insert("profiles")->values(["user_id" => 1, "bio" => "Builder"]));

        $user = $db
            ->select(DbUserWithProfile::class)
            ->with("profile.user")
            ->where("id", 1)
            ->first();

        $this->assertInstanceOf(DbUserWithProfile::class, $user);
        $this->assertInstanceOf(DbProfile::class, $user->profile);
        $this->assertSame("Builder", $user->profile->bio);
        $this->assertSame("Edin", $user->profile->user?->name);
    }

    public function testFindAndWithLoadNestedRelationsIncludingSharedAndMissingParents(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A'), ('B')");
        $db->execute("INSERT INTO invoice_lines VALUES ('A', 1), ('A', 2), (NULL, 3)");

        $invoice = DbInvoice::find("A", "lines.invoice.lines", "lines");
        $this->assertCount(2, $invoice->lines);
        $this->assertSame($invoice->lines[0]->invoice, $invoice->lines[1]->invoice);
        $this->assertCount(2, $invoice->lines[0]->invoice->lines);
        $this->assertSame([], DbInvoice::find("B", "lines.invoice")->lines);
        $this->assertNull(DbInvoice::find("missing", "lines.invoice"));

        $lines = DbInvoiceLine::query()->with("invoice", "invoice.lines")->orderBy("line_amount")->all();
        $this->assertCount(2, $lines[0]->invoice->lines);
        $this->assertSame($lines[0]->invoice, $lines[1]->invoice);
        $this->assertNull($lines[2]->invoice);
    }

    public function testUnknownNestedRelationIsRejectedEvenWhenTheParentCollectionIsEmpty(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A')");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Relation 'unknown' is not defined on " . DbInvoiceLine::class);
        DbInvoice::find("A", "lines.unknown");
    }

    public function testModelQueryJoinsBelongsToUsingMappedKeysAndAliases(): void
    {
        $db = new Db(self::memory());
        DbInvoiceLine::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A')");
        $db->execute("INSERT INTO invoice_lines VALUES ('A', 1), (NULL, 2), ('missing', 3)");

        $matched = DbInvoiceLine::query("l")->joinRelation("invoice")
            ->where("invoice.invoice_no", "A")
            ->pluck("l.line_amount");
        $this->assertSame([1.0], $matched);

        $rows = DbInvoiceLine::query()->asArrays()->leftJoinRelation("invoice", "i")
            ->columns("invoice_lines.line_amount", "i.invoice_no")
            ->orderBy("invoice_lines.line_amount");
        $this->assertSame([
            ["line_amount" => 1.0, "invoice_no" => "A"],
            ["line_amount" => 2.0, "invoice_no" => null],
            ["line_amount" => 3.0, "invoice_no" => null],
        ], $rows->all());
        $this->assertSame(3, $rows->paginate(2, 1)->totalCount());
    }

    public function testModelQueryCanJoinTwoRelationsToTheSameTableOnAProjection(): void
    {
        $db = new Db(self::memory());
        DbCategorizedArticle::useDb($db);
        $db->execute("CREATE TABLE articles (article_key TEXT, category_id INTEGER, fallback_id INTEGER)");
        $db->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO articles VALUES ('a', 1, 2)");
        $db->execute("INSERT INTO categories VALUES (1, 'Tech'), (2, 'General')");

        $item = DbCategorizedArticle::query("a")->asProjection(DbUserNameProjection::class)
            ->joinRelation("category", "c")->leftJoinRelation("fallback", "f")
            ->columns("f.name display_name")->where("c.name", "Tech")->first();
        $this->assertSame("General", $item?->name);
    }

    public function testNestedJoinsReuseAnAliasedParentAcrossProjectionsAndBranches(): void
    {
        $db = new Db(self::memory());
        DbArticleFeature::useDb($db);
        $db->execute("CREATE TABLE features (id INTEGER PRIMARY KEY, article_ref TEXT)");
        $db->execute("CREATE TABLE articles (article_key TEXT PRIMARY KEY, category_id INTEGER, fallback_id INTEGER)");
        $db->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)");
        $db->execute("INSERT INTO features VALUES (1, 'a'), (2, 'b'), (3, NULL)");
        $db->execute("INSERT INTO articles VALUES ('a', 1, 2), ('b', NULL, NULL)");
        $db->execute("INSERT INTO categories VALUES (1, 'Tech'), (2, 'General')");

        $base = DbArticleFeature::query("f")->joinRelation("article", "a");
        $rows = $base->asRows()->leftJoinRelation("article.category", "c")
            ->leftJoinRelation("article.fallback", "other")->leftJoinRelation("article.category", "c")
            ->columns("f.id", "c.name category", "other.name fallback")->orderBy("f.id");
        $this->assertCount(1, $base->query()->getJoins());
        $this->assertCount(3, $rows->query()->getJoins());
        $this->assertSame([
            ["id" => 1, "category" => "Tech", "fallback" => "General"],
            ["id" => 2, "category" => null, "fallback" => null],
        ], array_map(get_object_vars(...), $rows->all()));

        $inner = DbArticleFeature::query("f")->joinRelation("article.category");
        $this->assertSame(["Tech"], $inner->pluck("article_category.name"));
        $left = DbArticleFeature::query("f")->leftJoinRelation("article.category")->orderBy("f.id");
        $this->assertSame(["Tech", null, null], $left->pluck("article_category.name"));
    }

    public function testInvalidNestedJoinsLeaveTheQueryReusable(): void
    {
        DbTreeNode::useDb(new Db(self::memory()));
        $query = DbTreeNode::query("n");
        foreach (["parent.children", "parent.unknown", "parent."] as $path) {
            try {
                $query->joinRelation($path);
                $this->fail("Expected an invalid BelongsTo path.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString($path, $exception->getMessage());
            }
            $this->assertCount(0, $query->query()->getJoins());
        }
        $query->joinRelation("parent.parent.parent");
        $this->assertCount(3, $query->query()->getJoins());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Join alias 'N' is already in use.");
        $query->joinRelation("parent.parent.parent.parent", "N");
    }

    public function testRelationJoinRejectsHasMany(): void
    {
        DbInvoice::useDb(new Db(self::memory()));
        $this->expectException(\InvalidArgumentException::class);
        DbInvoice::query()->joinRelation("lines");
    }

    public function testHasManyFiltersKeepChildAlternativesCorrelatedAndPaginateParents(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A'), ('B'), ('C'), ('D')");
        $db->execute("INSERT INTO invoice_lines VALUES ('A', 10), ('A', 20), ('B', 30), (NULL, 20)");

        $any = DbInvoice::query()->whereHas("lines")->orderBy("invoice_no");
        $this->assertSame(["A", "B"], $any->pluck("invoice_no"));

        $amounts = static fn (WhereGroup $lines) => $lines->where("line_amount", 10)->orWhere("line_amount", 20);
        $filtered = DbInvoice::query("related")->group(static fn (WhereGroup $where) => $where
            ->where("related.invoice_no", "C")
            ->orWhereHas("lines", $amounts))
            ->orderBy("related.invoice_no");
        $this->assertSame(["A", "C"], $filtered->pluck("related.invoice_no"));
        $this->assertSame(["C", 10, 20], array_values($db->compile($filtered->query())->parameters));

        $page = $filtered->asRows()->where("related.invoice_no", Op::ne("C"))->paginate(1, 1);
        $this->assertSame(1, $page->totalCount());
        $this->assertSame("A", $page->first()->invoice_no);
    }

    public function testWhereHasRejectsBelongsTo(): void
    {
        DbInvoiceLine::useDb(new Db(self::memory()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Relationship 'invoice' must be a mapped HasMany relation.");
        DbInvoiceLine::query()->whereHas("invoice");
    }

    public function testModelQueryCountsAndSumsRelatedRowsWithoutChangingPagination(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A'), ('B'), ('C')");
        $db->execute("INSERT INTO invoice_lines VALUES ('A', 1.5), ('A', 2), ('B', NULL)");

        $rows = DbInvoice::query("related")->asArrays()->columns("related.invoice_no")
            ->withCount("lines")
            ->withSum("lines", "line_amount")
            ->orderBy("related.invoice_no");
        $this->assertSame([
            ["invoice_no" => "A", "lines_count" => 2, "lines_sum_line_amount" => 3.5],
            ["invoice_no" => "B", "lines_count" => 1, "lines_sum_line_amount" => null],
            ["invoice_no" => "C", "lines_count" => 0, "lines_sum_line_amount" => null],
        ], $rows->all());

        $page = DbInvoice::query()->columns("invoice_no")
            ->withCount("lines", "line_count")
            ->withSum("lines", "amount", alias: "total", default: 0)
            ->orderBy("invoice_no")
            ->asProjection(DbInvoiceSummary::class)
            ->paginate(3, 1);
        $summary = $page->first();
        $this->assertSame(3, $page->totalCount());
        $this->assertInstanceOf(DbInvoiceSummary::class, $summary);
        $this->assertSame("C", $summary->number);
        $this->assertSame(0, $summary->lineCount);
        $this->assertSame(0.0, $summary->total);
    }

    public function testFilteredCountsKeepChildAlternativesScopedAndBindEachCountIndependently(): void
    {
        $db = new Db(self::memory());
        DbInvoice::useDb($db);
        $db->execute("CREATE TABLE invoices (invoice_no TEXT PRIMARY KEY)");
        $db->execute("CREATE TABLE invoice_lines (invoice_ref TEXT, line_amount REAL)");
        $db->execute("INSERT INTO invoices VALUES ('A'), ('B'), ('C'), ('D')");
        $db->execute("INSERT INTO invoice_lines VALUES ('A', 0), ('A', 2), ('A', 4), ('B', 3), ('D', 4)");

        $query = DbInvoice::query("related")->columns("related.invoice_no")
            ->withCount("lines", constraint: static fn (WhereGroup $lines) => $lines
                ->where("line_amount", 2)->orWhere("line_amount", 4))
            ->withCount("lines", "large", static fn (WhereGroup $lines) => $lines->where("line_amount", Op::gt(3)))
            ->where("related.invoice_no", Op::ne("D"))
            ->orderBy("related.invoice_no");
        $this->assertSame([2, 4, 3, "D"], array_values($db->compile($query->query())->parameters));
        $this->assertSame([
            ["invoice_no" => "A", "lines_count" => 2, "large" => 1],
            ["invoice_no" => "B", "lines_count" => 0, "large" => 0],
            ["invoice_no" => "C", "lines_count" => 0, "large" => 0],
        ], $query->asArrays()->all());
        $page = $query->asRows()->paginate(2, 1);
        $this->assertSame(3, $page->totalCount());
        $this->assertSame("B", $page->first()->invoice_no);
        $this->assertSame(0, $page->first()->lines_count);
    }

    public function testNestedCountsFollowMappedKeysAndFilterOnlyTheFinalRelation(): void
    {
        $db = new Db(self::memory());
        DbTreeNode::useDb($db);
        $db->execute("CREATE TABLE nodes (node_id INTEGER PRIMARY KEY, parent_id INTEGER, state TEXT)");
        $db->execute("INSERT INTO nodes VALUES
            (1, NULL, 'root'), (2, NULL, 'root'), (3, NULL, 'root'),
            (10, 1, 'branch'), (11, 1, 'branch'), (12, 2, 'branch'),
            (20, 10, 'open'), (21, 10, 'closed'), (22, 11, 'pending'),
            (30, 20, 'open'), (31, 21, 'open')");

        $roots = DbTreeNode::query("related_path_0")->columns("node_id")
            ->withCount("children.children")
            ->withCount("children.children", "matching", static fn (WhereGroup $nodes) =>
                $nodes->where("state", "open")->orWhere("state", "pending"))
            ->withCount("children.children.children", "great_grandchildren")
            ->where("parent_id", null)->orderBy("node_id");

        $this->assertSame([
            ["node_id" => 1, "children_children_count" => 3, "matching" => 2, "great_grandchildren" => 2],
            ["node_id" => 2, "children_children_count" => 0, "matching" => 0, "great_grandchildren" => 0],
            ["node_id" => 3, "children_children_count" => 0, "matching" => 0, "great_grandchildren" => 0],
        ], $roots->asArrays()->all());
        $page = $roots->asRows()->paginate(1, 1);
        $this->assertSame(3, $page->totalCount());
        $this->assertSame(2, $page->first()->matching);
    }

    public function testNestedCountRejectsABelongsToSegment(): void
    {
        DbInvoice::useDb(new Db(self::memory()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Relationship 'invoice' must be a mapped HasMany relation.");
        DbInvoice::query()->withCount("lines.invoice");
    }

    public function testRelationshipSumRequiresAMappedColumn(): void
    {
        DbInvoice::useDb(new Db(self::memory()));
        $this->expectException(\InvalidArgumentException::class);
        DbInvoice::query()->withSum("lines", "missing");
    }

    public function testWithCountHydratesOnlyAnExplicitIntProjection(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT, category_id INTEGER)");
        $db->connection()->pdo()->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, article_id INTEGER, body TEXT)");
        $db->connection()->pdo()->exec("INSERT INTO articles VALUES (1, 'First', 1), (2, 'Second', 1)");
        $db->connection()->pdo()->exec("INSERT INTO comments VALUES (1, 1, 'One'), (2, 1, 'Two')");

        $plain = $db->select(DbArticleWithComments::class)->where("id", 1)->first();
        $this->assertInstanceOf(DbArticleWithComments::class, $plain);
        $this->assertNull($plain->commentsCount);
        [$first, $second] = $db->select(DbArticleWithComments::class)->withCount("comments")->orderBy("id")->all();
        $this->assertInstanceOf(DbArticleWithComments::class, $first);
        $this->assertInstanceOf(DbArticleWithComments::class, $second);
        $this->assertSame(2, $first->commentsCount);
        $this->assertSame(0, $second->commentsCount);
        $this->assertSame([], $first->comments);

        $first->title = "Updated";
        $this->assertSame(1, $db->save($first));
        $this->assertSame("Updated", $db->select("articles")->columns("title")->where("id", 1)->scalar());

        $this->expectException(\InvalidArgumentException::class);
        $db->select(DbArticleWithComments::class)->withCount("comments", "id")->first();
    }

    public function testDbSelectCanLoadHasManyRelation(): void
    {
        $db = new Db(self::memory());
        $db->connection()->pdo()->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, category_id INTEGER)");
        $db->connection()->pdo()->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, article_id INTEGER, body TEXT)");

        $db->execute(Query::insert("articles")->values(["title" => "Atom", "category_id" => 1]));
        $db->execute(Query::insert("comments")->values(["article_id" => 1, "body" => "Second"]));
        $db->execute(Query::insert("comments")->values(["article_id" => 1, "body" => "First"]));

        $article = $db
            ->select(DbArticleWithComments::class)
            ->with("comments")
            ->where("id", 1)
            ->first();

        $this->assertInstanceOf(DbArticleWithComments::class, $article);
        $this->assertCount(2, $article->comments);
        $this->assertSame("First", $article->comments[0]->body);
        $this->assertSame("Second", $article->comments[1]->body);

        $page = $db->select(DbArticleWithComments::class)->with("comments")->orderBy("id")->paginate(1, 1);
        $this->assertSame(1, $page->totalCount());
        $pagedArticle = $page->first();
        $this->assertInstanceOf(DbArticleWithComments::class, $pagedArticle);
        $this->assertEquals($article->comments, $pagedArticle->comments);
    }

    private static function memory(): PdoConnection
    {
        $pdo = new PDO("sqlite::memory:");
        $pdo->setAttribute(PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES, true);
        $pdo->exec("PRAGMA foreign_keys = ON");

        return new PdoConnection($pdo, new SqliteCompiler());
    }
}

#[Table("users")]
final class DbHydratedUser implements HydrationAwareInterface
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("name")]
    public string $name;

    public ?string $originalName = null;

    public function afterHydration(EntityMetadata $metadata): void
    {
        $this->originalName = $metadata->column("name")?->getValue($this);
    }
}

final readonly class DbUserNameProjection
{
    #[Column("display_name", insert: false, update: false)]
    public string $name;
}

#[Table("users")]
final class DbModelUser extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("name")]
    public string $name;
}

#[Table("tracked_users")]
final class DbTrackedUser extends Model
{
    #[PrimaryKey]
    public int $id;
    #[Column]
    public string $name;
    #[Column]
    public ?string $note;
    #[Column("private_label", select: false)]
    public string $privateLabel = "";
    #[Column("optional_code", select: false)]
    public ?string $optionalCode;
}

#[Table("categories")]
final class DbCategory
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("name")]
    public string $name;
}

#[Table("features")]
final class DbArticleFeature extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("article_ref")]
    public ?string $articleRef;

    #[BelongsTo(DbCategorizedArticle::class, foreignKey: "articleRef", ownerKey: "key")]
    public ?DbCategorizedArticle $article = null;
}

#[Table("articles")]
final class DbCategorizedArticle extends Model
{
    #[PrimaryKey("article_key", autoIncrement: false)]
    public string $key;

    #[Column("category_id")]
    public int $categoryId;

    #[Column("fallback_id")]
    public int $fallbackId;

    #[BelongsTo(DbCategory::class, foreignKey: "category_id", ownerKey: "id")]
    public ?DbCategory $category = null;

    #[BelongsTo(DbCategory::class, foreignKey: "fallback_id", ownerKey: "id")]
    public ?DbCategory $fallback = null;
}

#[Table("articles")]
final class DbArticle
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("title")]
    public string $title;

    #[Column("category_id")]
    public int $categoryId;

    #[BelongsTo(DbCategory::class, foreignKey: "category_id", ownerKey: "id")]
    public ?DbCategory $category = null;
}

#[Table("users")]
final class DbUserWithProfile
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("name")]
    public string $name;

    #[HasOne(DbProfile::class, foreignKey: "user_id", localKey: "id")]
    public ?DbProfile $profile = null;
}

#[Table("profiles")]
final class DbProfile
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("user_id")]
    public int $userId;

    #[Column("bio")]
    public string $bio;

    #[BelongsTo(DbUserWithProfile::class, foreignKey: "user_id", ownerKey: "id")]
    public ?DbUserWithProfile $user = null;
}

#[Table("articles")]
final class DbArticleWithComments
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("title")]
    public string $title;

    #[Column("category_id")]
    public int $categoryId;

    #[HasMany(DbComment::class, foreignKey: "article_id", localKey: "id", orderBy: ["body", "id"])]
    public array $comments = [];

    #[Projection("comments_count")]
    public ?int $commentsCount = null;
}

#[Table("comments")]
final class DbComment
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("article_id")]
    public int $articleId;

    #[Column("body")]
    public string $body;
}

#[Table("invoices")]
final class DbInvoice extends Model
{
    #[PrimaryKey("invoice_no", autoIncrement: false)]
    public string $number;

    #[HasMany(DbInvoiceLine::class, foreignKey: "invoice_ref", localKey: "invoice_no")]
    public array $lines = [];
}

#[Table("invoice_lines")]
final class DbInvoiceLine extends Model
{
    #[Column("invoice_ref")]
    public ?string $invoiceNumber;

    #[BelongsTo(DbInvoice::class, foreignKey: "invoiceNumber", ownerKey: "number")]
    public ?DbInvoice $invoice = null;

    #[Column("line_amount")]
    public ?float $amount;
}

#[Table("nodes")]
final class DbTreeNode extends Model
{
    #[PrimaryKey("node_id")]
    public int $id;

    #[Column("parent_id")]
    public ?int $parentId;

    #[HasMany(self::class, foreignKey: "parentId", localKey: "id")]
    public array $children = [];

    #[BelongsTo(self::class, foreignKey: "parentId", ownerKey: "id")]
    public ?self $parent = null;
}

final readonly class DbInvoiceSummary
{
    #[Projection("invoice_no")]
    public string $number;

    #[Projection("line_count")]
    public int $lineCount;

    #[Projection]
    public float $total;
}

#[Table("timestamped_posts")]
final class DbTimestampedPost extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("title")]
    public string $title;

    #[Column("created_at", onInsert: NowProvider::class)]
    public DateTimeImmutable $createdAt;

    #[Column("updated_at", onInsert: NowProvider::class, onUpdate: NowProvider::class)]
    public DateTimeImmutable $updatedAt;
}

#[Table("users")]
final class DbUserWithNameProvider extends Model
{
    #[PrimaryKey]
    public int $id;

    #[Column(onUpdate: DbUppercaseProvider::class)]
    public string $name;
}

final class DbUppercaseProvider implements ColumnValueProviderInterface
{
    public function value(object $entity, ColumnMetadata $column): string
    {
        return strtoupper($column->getValue($entity));
    }
}
