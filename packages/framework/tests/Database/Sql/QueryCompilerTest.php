<?php

declare(strict_types=1);

namespace Atom\Tests\Database\Sql;

use Atom\Database\Sql\Compiler\MySqlCompiler;
use Atom\Database\Sql\Compiler\SqliteCompiler;
use Atom\Database\Sql\Op;
use Atom\Database\Sql\Query;
use PHPUnit\Framework\TestCase;

final class QueryCompilerTest extends TestCase
{
    public function testCompilesSelectQuery(): void
    {
        $query = Query::select("users")
            ->columns("id", "name")
            ->where("age", Op::gte(18));

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame("SELECT `id`, `name` FROM `users` WHERE `age` >= :p1", $command->sql);
        $this->assertSame([":p1" => 18], $command->parameters);
    }

    public function testSqliteCompilerUsesDoubleQuotedIdentifiers(): void
    {
        $query = Query::select("users u")
            ->columns("u.id", "u.name")
            ->where("u.age", Op::gte(18));

        $command = (new SqliteCompiler())->compile($query);

        $this->assertSame('SELECT "u"."id", "u"."name" FROM "users" "u" WHERE "u"."age" >= :p1', $command->sql);
        $this->assertSame([":p1" => 18], $command->parameters);
    }

    public function testNormalizesCommonWhereValues(): void
    {
        $query = Query::select("users u")
            ->where("u.status", "active")
            ->where("u.id", [1, 2, 3])
            ->where("u.deleted_at", null);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT * FROM `users` `u` WHERE `u`.`status` = :p1 AND `u`.`id` IN (:p2, :p3, :p4) AND `u`.`deleted_at` IS NULL",
            $command->sql
        );
        $this->assertSame([":p1" => "active", ":p2" => 1, ":p3" => 2, ":p4" => 3], $command->parameters);
    }

    public function testCompilesOffsetWithoutLimitForSqliteAndMySql(): void
    {
        $query = Query::select("users")->orderBy("id")->offset(2);

        $this->assertSame('SELECT * FROM "users" ORDER BY "id" ASC LIMIT -1 OFFSET 2',
            (new SqliteCompiler())->compile($query)->sql);
        $this->assertSame("SELECT * FROM `users` ORDER BY `id` ASC LIMIT 18446744073709551615 OFFSET 2",
            (new MySqlCompiler())->compile($query)->sql);
    }

    public function testCompilesOrderingAndLimit(): void
    {
        $query = Query::select("users")
            ->columns("id", "name")
            ->orderBy("department", "name")
            ->orderByDesc("priority", "id")
            ->offset(20)
            ->limit(10);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT `id`, `name` FROM `users` ORDER BY `department` ASC, `name` ASC, `priority` DESC, `id` DESC LIMIT 20, 10",
            $command->sql
        );
        $this->assertSame([], $command->parameters);
    }

    public function testCompilesGroupBy(): void
    {
        $query = Query::select("users u")
            ->columns("u.role", "u.status")
            ->groupBy("u.role", "u.status")
            ->orderBy("u.role");

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT `u`.`role`, `u`.`status` FROM `users` `u` GROUP BY `u`.`role`, `u`.`status` ORDER BY `u`.`role` ASC",
            $command->sql
        );
        $this->assertSame([], $command->parameters);
    }

    public function testCompilesCount(): void
    {
        $query = Query::select("users u")
            ->count("u.id", "total")
            ->where("u.active", true);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT COUNT(`u`.`id`) `total` FROM `users` `u` WHERE `u`.`active` = :p1",
            $command->sql
        );
        $this->assertSame([":p1" => true], $command->parameters);
    }

    public function testCompilesHaving(): void
    {
        $query = Query::select("users u")
            ->columns("u.role")
            ->groupBy("u.role")
            ->having("count", Op::gt(1))
            ->orHaving("u.role", "admin");

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT `u`.`role` FROM `users` `u` GROUP BY `u`.`role` HAVING `count` > :p1 OR `u`.`role` = :p2",
            $command->sql
        );
        $this->assertSame([":p1" => 1, ":p2" => "admin"], $command->parameters);
    }

    public function testCompilesGroupedWhereConditions(): void
    {
        $query = Query::select("users")
            ->where("status", "active")
            ->group(function ($where) {
                $where
                    ->where("age", Op::gte(18))
                    ->orWhere("role", "admin");
            });

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT * FROM `users` WHERE `status` = :p1 AND (`age` >= :p2 OR `role` = :p3)",
            $command->sql
        );
        $this->assertSame([":p1" => "active", ":p2" => 18, ":p3" => "admin"], $command->parameters);
    }

    public function testCompilesJoins(): void
    {
        $query = Query::select("users u")
            ->columns("u.id", "p.name profileName")
            ->leftJoin("profiles p", function ($join) {
                $join->on("p.user_id", Op::column("u.id"));
            })
            ->where("u.active", true);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT `u`.`id`, `p`.`name` `profileName` FROM `users` `u` LEFT JOIN `profiles` `p` ON `p`.`user_id` = `u`.`id` WHERE `u`.`active` = :p1",
            $command->sql
        );
        $this->assertSame([":p1" => true], $command->parameters);
    }

    public function testParsesJoinOnExpression(): void
    {
        $query = Query::select("users u")
            ->columns("u.id", "p.name profileName")
            ->leftJoin("profiles p on p.user_id = u.id")
            ->where("u.active", true);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT `u`.`id`, `p`.`name` `profileName` FROM `users` `u` LEFT JOIN `profiles` `p` ON `p`.`user_id` = `u`.`id` WHERE `u`.`active` = :p1",
            $command->sql
        );
        $this->assertSame([":p1" => true], $command->parameters);
    }

    public function testCompilesWhereExpression(): void
    {
        $query = Query::select("users u")
            ->whereExp("u.id = :id and u.id < 100", ["id" => 2]);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT * FROM `users` `u` WHERE `u`.`id` = :id AND `u`.`id` < 100",
            $command->sql
        );
        $this->assertSame([":id" => 2], $command->parameters);
    }

    public function testGeneratedBindingBeforeCallerBindingPreservesBothValues(): void
    {
        $query = Query::select("users")
            ->where("id", 7)
            ->whereExp("role = :p1", ["p1" => "editor"]);
        $compiler = new SqliteCompiler();
        $command = $compiler->compile($query);

        $this->assertSame('SELECT * FROM "users" WHERE "id" = :p2 AND "role" = :p1', $command->sql);
        $this->assertSame([":p2" => 7, ":p1" => "editor"], $command->parameters);
        $this->assertSame([":p1" => 9], $compiler->compile(Query::select("users")->where("id", 9))->parameters);
    }

    public function testCallerBindingsBeforeGeneratedBindingReserveEvenNullValues(): void
    {
        $query = Query::select("users")
            ->whereExp("role = :p1 and deleted_at IS :p2", [":p1" => "editor", "p2" => null])
            ->where("id", 7);
        $command = (new SqliteCompiler())->compile($query);

        $this->assertSame('SELECT * FROM "users" WHERE "role" = :p1 AND "deleted_at" IS :p2 AND "id" = :p3', $command->sql);
        $this->assertSame([":p1" => "editor", ":p2" => null, ":p3" => 7], $command->parameters);
    }

    public function testCompilesCorrelatedExistsExpression(): void
    {
        $query = Query::select("orders")
            ->where("user_id", 42)
            ->whereExp(
                "EXISTS (SELECT 1 FROM order_return_requests r WHERE r.order_id = orders.id AND r.status = :returnStatus)",
                ["returnStatus" => 1]
            );

        $command = (new SqliteCompiler())->compile($query);

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "user_id" = :p1 AND EXISTS (SELECT 1 FROM "order_return_requests" "r" WHERE "r"."order_id" = "orders"."id" AND "r"."status" = :returnStatus)',
            $command->sql
        );
        $this->assertSame([":p1" => 42, ":returnStatus" => 1], $command->parameters);
    }

    public function testCompilesHavingExpression(): void
    {
        $query = Query::select("users u")
            ->columns("u.role")
            ->groupBy("u.role")
            ->havingExp("count > :count", ["count" => 1]);

        $command = (new MySqlCompiler())->compile($query);

        $this->assertSame(
            "SELECT `u`.`role` FROM `users` `u` GROUP BY `u`.`role` HAVING `count` > :count",
            $command->sql
        );
        $this->assertSame([":count" => 1], $command->parameters);
    }
}
