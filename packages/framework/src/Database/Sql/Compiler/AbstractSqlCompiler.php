<?php

declare(strict_types=1);

namespace Atom\Database\Sql\Compiler;

use Atom\Database\Sql\Column;
use Atom\Database\Sql\Command;
use Atom\Database\Sql\Condition;
use Atom\Database\Sql\CriteriaExpression;
use Atom\Database\Sql\DeleteQuery;
use Atom\Database\Sql\ExistsCondition;
use Atom\Database\Sql\InSubqueryCondition;
use Atom\Database\Sql\InsertQuery;
use Atom\Database\Sql\Join;
use Atom\Database\Sql\Op;
use Atom\Database\Sql\ScalarSubquery;
use Atom\Database\Sql\SelectExpression;
use Atom\Database\Sql\SelectQuery;
use Atom\Database\Sql\Sort;
use Atom\Database\Sql\SqlQueryInterface;
use Atom\Database\Sql\Table;
use Atom\Database\Sql\UpdateQuery;
use Atom\Database\Sql\UpsertQuery;
use Atom\Database\Sql\WhereGroup;
use RuntimeException;

abstract class AbstractSqlCompiler implements QueryCompilerInterface
{
    /** @var array<string, mixed> */
    private array $parameters = [];
    /** @var array<string, true> */
    private array $callerParameterNames = [];
    private bool $hasBindingCollision = false;
    private int $parameterIndex = 0;

    public function compile(SqlQueryInterface $query): Command
    {
        $this->callerParameterNames = [];
        $this->hasBindingCollision = false;
        $command = $this->compileQuery($query);

        // A later clause can claim a name already generated in an earlier one.
        // At this point every caller binding, including those in subqueries, is known.
        return $this->hasBindingCollision ? $this->compileQuery($query) : $command;
    }

    /** @phpstan-impure Collects caller binding names and detects collisions during compilation. */
    private function compileQuery(SqlQueryInterface $query): Command
    {
        $this->parameters = [];
        $this->parameterIndex = 0;

        return match (true) {
            $query instanceof SelectQuery => $this->compileSelect($query),
            $query instanceof InsertQuery => $this->compileInsert($query),
            $query instanceof UpsertQuery => $this->compileUpsert($query),
            $query instanceof UpdateQuery => $this->compileUpdate($query),
            $query instanceof DeleteQuery => $this->compileDelete($query),
            default => throw new RuntimeException("Unsupported SQL query type."),
        };
    }

    protected function compileSelect(SelectQuery $query): Command
    {
        $sql = "SELECT " . $this->compileColumns($query->getColumns());
        $from = $query->getFrom();
        if ($from === null) {
            throw new RuntimeException("Select query must define a table.");
        }

        $sql .= " FROM " . $this->compileTable($from);

        $joins = $this->compileJoins($query->getJoins());
        if ($joins !== "") {
            $sql .= " " . $joins;
        }

        $where = $this->compileWhere($query->getWhere());
        if ($where !== "") {
            $sql .= " WHERE " . $where;
        }

        $groupBy = $this->compileGroupBy($query->getGroupBy());
        if ($groupBy !== "") {
            $sql .= " GROUP BY " . $groupBy;
        }

        $having = $this->compileWhere($query->getHaving());
        if ($having !== "") {
            $sql .= " HAVING " . $having;
        }

        $orderBy = $this->compileOrderBy($query->getOrderBy());
        if ($orderBy !== "") {
            $sql .= " ORDER BY " . $orderBy;
        }

        $sql .= $this->compileLimit($query->getLimit(), $query->getOffset());

        return new Command($sql, $this->parameters);
    }

    protected function compileInsert(InsertQuery $query): Command
    {
        $table = $query->getTable();
        if ($table === null) {
            throw new RuntimeException("Insert query must define a table.");
        }

        $values = $query->getValues();
        if ($values === []) {
            throw new RuntimeException("Insert query must define values.");
        }

        $columns = array_keys($values);
        $sql = "INSERT INTO " . $this->compileTable($table);
        $sql .= " (" . implode(", ", array_map(fn(string $column) => $this->compileColumn(Column::from($column)), $columns)) . ")";
        $sql .= " VALUES (" . implode(", ", array_map(fn(mixed $value) => $this->bind($value), $values)) . ")";

        return new Command($sql, $this->parameters);
    }

    protected function compileUpsert(UpsertQuery $query): Command
    {
        $columns = array_map($this->quoteIdentifier(...), $query->columns);
        $tuples = [];
        foreach ($query->rows as $row) {
            $bindings = [];
            foreach ($query->columns as $column) {
                $bindings[] = $this->bind($row[$column]);
            }
            $tuples[] = "(" . implode(", ", $bindings) . ")";
        }

        $sql = "INSERT INTO " . $this->quoteIdentifier($query->table) . " (" . implode(", ", $columns) . ") VALUES " . implode(", ", $tuples);
        if ($query->update !== []) {
            $sql .= $this->compileUpsertConflict($query);
        }
        return new Command($sql, $this->parameters);
    }

    protected function compileUpsertConflict(UpsertQuery $query): string
    {
        $keys = array_map($this->quoteIdentifier(...), $query->uniqueBy);
        $assignments = [];
        foreach ($query->update as $column) {
            $name = $this->quoteIdentifier($column);
            $assignments[] = "{$name} = excluded.{$name}";
        }
        return " ON CONFLICT (" . implode(", ", $keys) . ") DO UPDATE SET " . implode(", ", $assignments);
    }

    protected function compileUpdate(UpdateQuery $query): Command
    {
        $table = $query->getTable();
        if ($table === null) {
            throw new RuntimeException("Update query must define a table.");
        }

        $values = $query->getValues();
        if ($values === []) {
            throw new RuntimeException("Update query must define values.");
        }

        $assignments = [];
        foreach ($values as $column => $value) {
            $assignments[] = $this->compileColumn(Column::from($column)) . " = " . $this->bind($value);
        }

        $sql = "UPDATE " . $this->compileTable($table) . " SET " . implode(", ", $assignments);
        $where = $this->compileWhere($query->getWhere());
        if ($where !== "") {
            $sql .= " WHERE " . $where;
        }

        return new Command($sql, $this->parameters);
    }

    protected function compileDelete(DeleteQuery $query): Command
    {
        $table = $query->getTable();
        if ($table === null) {
            throw new RuntimeException("Delete query must define a table.");
        }

        $sql = "DELETE FROM " . $this->compileTable($table);
        $where = $this->compileWhere($query->getWhere());
        if ($where !== "") {
            $sql .= " WHERE " . $where;
        }

        $sql .= $this->compileDeleteLimit($query->getLimit());

        return new Command($sql, $this->parameters);
    }

    /**
     * @param array<int, Column|SelectExpression|ScalarSubquery> $columns
     */
    protected function compileColumns(array $columns): string
    {
        if ($columns === []) {
            return "*";
        }

        return implode(", ", array_map(
            fn(Column|SelectExpression|ScalarSubquery $column) => match (true) {
                $column instanceof ScalarSubquery => "(" . $this->compileSelect($column->query)->sql . ") " . $this->quoteIdentifier($column->alias),
                $column instanceof SelectExpression => $this->compileSelectExpression($column),
                $column->name === "*" && $column->alias === null => $column->table === null
                    ? "*"
                    : $this->quoteIdentifier($column->table) . ".*",
                default => $this->compileColumn($column),
            },
            $columns
        ));
    }

    protected function compileSelectExpression(SelectExpression $expression): string
    {
        $this->bindNamedParameters($expression->parameters);
        $sql = $expression->aggregate ? $this->compileAggregateExpression($expression->expression) : $expression->expression;
        return $expression->alias === null ? $sql : $sql . " " . $this->quoteIdentifier($expression->alias);
    }

    protected function compileAggregateExpression(string $expression): string
    {
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\((.*)\)$/i', $expression, $matches)) {
            $function = strtoupper($matches[1]);
            $argument = trim($matches[2]);
            $argument = $argument === "*" ? "*" : $this->compileColumn(Column::from($argument));

            return "{$function}({$argument})";
        }

        return $this->quoteExpression($expression);
    }

    protected function compileTable(Table $table): string
    {
        $name = $this->quoteIdentifier($table->name);
        return $table->alias === null ? $name : $name . " " . $this->quoteIdentifier($table->alias);
    }

    protected function compileColumn(Column $column): string
    {
        $name = $this->quoteIdentifier($column->name);
        if ($column->table !== null) {
            $name = $this->quoteIdentifier($column->table) . "." . $name;
        }

        return $column->alias === null ? $name : $name . " " . $this->quoteIdentifier($column->alias);
    }

    /**
     * @param Join[] $joins
     */
    protected function compileJoins(array $joins): string
    {
        return implode(" ", array_map(fn(Join $join) => $this->compileJoin($join), $joins));
    }

    protected function compileJoin(Join $join): string
    {
        $on = $this->compileWhere($join->getOn());
        if ($on === "") {
            throw new RuntimeException("Join must define at least one ON condition.");
        }

        return "{$join->type} " . $this->compileTable($join->table) . " ON " . $on;
    }

    /**
     * @param WhereGroup $group
     */
    protected function compileWhere(WhereGroup $group): string
    {
        $parts = [];

        foreach ($group->getItems() as $item) {
            if ($item instanceof WhereGroup) {
                $nested = $this->compileWhere($item);
                $compiled = $nested === "" ? "" : "({$nested})";
            } elseif ($item instanceof ExistsCondition) {
                $compiled = "EXISTS (" . $this->compileSelect($item->query)->sql . ")";
            } elseif ($item instanceof InSubqueryCondition) {
                $compiled = $this->compileColumn($item->column) . " IN (" . $this->compileSelect($item->query)->sql . ")";
            } elseif ($item instanceof CriteriaExpression) {
                $compiled = $this->compileCriteriaExpression($item);
            } else {
                $compiled = $this->compileCondition($item);
            }

            if ($compiled !== "") {
                $parts[] = ($parts === [] ? "" : " {$item->boolean} ") . $compiled;
            }
        }

        return implode("", $parts);
    }

    protected function compileCondition(Condition $condition): string
    {
        $column = $this->compileColumn($condition->column);
        $op = $condition->op;

        return match ($op->operator) {
            "IS NULL", "IS NOT NULL" => "{$column} {$op->operator}",
            "IN" => "{$column} IN " . $this->compileValueList($op->value),
            "BETWEEN" => "{$column} BETWEEN {$this->bind($op->value)} AND {$this->bind($op->maxValue)}",
            "LIKE" => "{$column} LIKE {$this->bind($op->value)}" .
                ($op->escape === null ? "" : " ESCAPE {$this->bind($op->escape)}"),
            "=" => "{$column} = " . ($op->value instanceof Column ? $this->compileColumn($op->value) : $this->bind($op->value)),
            "<>", ">=", "<=", ">", "<" => "{$column} {$op->operator} " .
                ($op->value instanceof Column ? $this->compileColumn($op->value) : $this->bind($op->value)),
            default => "{$column} {$op->operator} " . $this->bind($op->value),
        };
    }

    protected function compileCriteriaExpression(CriteriaExpression $expression): string
    {
        $this->bindNamedParameters($expression->parameters);
        return $this->quoteExpression($expression->expression);
    }

    /** @param array<string, mixed> $parameters */
    private function bindNamedParameters(array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $parameterName = str_starts_with((string) $name, ":") ? (string) $name : ":" . $name;
            if (!isset($this->callerParameterNames[$parameterName]) && array_key_exists($parameterName, $this->parameters)) {
                $this->hasBindingCollision = true;
            }
            $this->callerParameterNames[$parameterName] = true;
            $this->parameters[$parameterName] = $value;
        }
    }

    /**
     * @param array<int, mixed> $values
     */
    protected function compileValueList(array $values): string
    {
        return "(" . implode(", ", array_map(fn(mixed $value) => $this->bind($value), $values)) . ")";
    }

    /**
     * @param Sort[] $sorts
     */
    protected function compileOrderBy(array $sorts): string
    {
        $parts = [];
        foreach ($sorts as $sort) {
            $this->bindNamedParameters($sort->parameters);
            $column = $sort->column instanceof Column ? $this->compileColumn($sort->column) : $sort->column;
            $parts[] = $column . " " . $sort->direction;
        }
        return implode(", ", $parts);
    }

    /**
     * @param Column[] $columns
     */
    protected function compileGroupBy(array $columns): string
    {
        return implode(", ", array_map(fn(Column $column) => $this->compileColumn($column), $columns));
    }

    protected function bind(mixed $value): string
    {
        do {
            $name = ":p" . ++$this->parameterIndex;
        } while (isset($this->callerParameterNames[$name]));
        $this->parameters[$name] = $value;
        return $name;
    }

    protected function compileLimit(?int $limit, ?int $offset): string
    {
        if ($limit !== null && $offset !== null) {
            return " LIMIT {$offset}, {$limit}";
        }

        if ($limit !== null) {
            return " LIMIT {$limit}";
        }

        return "";
    }

    protected function compileDeleteLimit(?int $limit): string
    {
        return $limit === null ? "" : " LIMIT {$limit}";
    }

    protected function quoteIdentifier(string $name): string
    {
        return "`" . str_replace("`", "``", $name) . "`";
    }

    protected function quoteExpression(string $expression): string
    {
        $keywords = [
            "AND" => true,
            "OR" => true,
            "NOT" => true,
            "IS" => true,
            "NULL" => true,
            "IN" => true,
            "LIKE" => true,
            "ILIKE" => true,
            "ESCAPE" => true,
            "BETWEEN" => true,
            "EXISTS" => true,
            "SELECT" => true,
            "FROM" => true,
            "WHERE" => true,
            "TRUE" => true,
            "FALSE" => true,
        ];

        return preg_replace_callback(
            "/'[^']*(?:''[^']*)*'|:[A-Za-z_][A-Za-z0-9_]*|\\b[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)?\\b/",
            function (array $matches) use ($keywords): string {
                $token = $matches[0];

                if ($token[0] === "'" || $token[0] === ":") {
                    return $token;
                }

                $upper = strtoupper($token);
                if (isset($keywords[$upper])) {
                    return $upper;
                }

                if (str_contains($token, ".")) {
                    return implode(".", array_map(fn(string $part) => $this->quoteIdentifier($part), explode(".", $token)));
                }

                return $this->quoteIdentifier($token);
            },
            $expression
        );
    }
}
