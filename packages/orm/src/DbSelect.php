<?php

declare(strict_types=1);

namespace Atom\Database;

use Atom\Database\Orm\EntityMetadataFactory;
use Atom\Database\Orm\HasManyQuery;
use Atom\Database\Orm\PageResult;
use Atom\Database\Orm\PdoConnection;
use Atom\Database\Orm\RelationMetadata;
use Atom\Database\Sql\Column;
use Atom\Database\Sql\Command;
use Atom\Database\Sql\Join;
use Atom\Database\Sql\Op;
use Atom\Database\Sql\SelectExpression;
use Atom\Database\Sql\SelectQuery;
use Atom\Database\Sql\WhereGroup;
use InvalidArgumentException;

/** @template TRow of object|array<string, mixed> */
final class DbSelect
{
    /** @var class-string|null */
    private readonly ?string $sourceClass;
    /** @var class-string|null */
    private ?string $resultClass;
    private ?string $modelResultError = null;

    /**
     * @param class-string|null $entityClass
     * @param class-string|null $rowModelClass Source of relationship mappings for array results.
     */
    public function __construct(
        private readonly Db $db,
        private readonly SelectQuery $query,
        ?string $entityClass = null,
        ?string $rowModelClass = null,
    ) {
        $this->sourceClass = $rowModelClass ?? $entityClass;
        $this->resultClass = $entityClass;
    }

    /** @var string[] */
    private array $relations = [];

    public function query(): SelectQuery
    {
        return $this->query;
    }

    /** @return self<\stdClass> */
    public function asRows(): self
    {
        return $this->asProjection(\stdClass::class);
    }

    /** @return self<array<string, mixed>> */
    public function asArrays(): self
    {
        if ($this->relations !== []) {
            throw new InvalidArgumentException("Eager loading requires model results.");
        }

        /** @var self<array<string, mixed>> $rows */
        $rows = new self($this->db, (clone $this->query)->setModelColumns(), rowModelClass: $this->sourceClass);
        return $rows;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return self<T>
     */
    public function asProjection(string $className): self
    {
        if (is_a($className, Model::class, true)) {
            throw new InvalidArgumentException("A projection requires a class without model persistence.");
        }
        /** @var self<T> $projection */
        $projection = $this->asArrays();
        $projection->resultClass = $className;
        return $projection;
    }

    public function columns(string ...$columns): self
    {
        $this->query->columns(...$columns);
        return $this;
    }

    public function count(string $column = "*", ?string $alias = null): self
    {
        $this->query->count($column, $alias);
        return $this;
    }

    /** @param (callable(WhereGroup): mixed)|null $constraint */
    public function withCount(string $relation, ?string $alias = null, ?callable $constraint = null): self
    {
        $path = explode(".", $relation);
        $alias ??= implode("_", $path) . "_count";
        $child = new HasManyQuery($this->db, $this->sourceClass, $this->query->getFrom(), $path, $constraint);
        if ($this->resultClass === $this->sourceClass) {
            $destination = $child->parent->column($alias);
            if ($destination === null || $destination->columnName !== $alias || $destination->propertyType !== "int"
                || $destination->select || $destination->insert || $destination->update) {
                $this->modelResultError = "Relationship count '{$alias}' must map to an int projection property on a model result.";
            }
        }

        $this->query->subqueryColumn($child->query->count(), $alias);
        return $this;
    }

    public function withSum(string $relation, string $column, ?string $alias = null, int|float|null $default = null): self
    {
        $child = new HasManyQuery($this->db, $this->sourceClass, $this->query->getFrom(), $relation);
        $field = $child->related->column($column);
        if ($field === null || !$field->select) {
            throw new InvalidArgumentException("Relationship sum '{$column}' must be a mapped selectable column.");
        }
        $aggregate = $child->query->sum("{$child->alias}.{$field->columnName}");
        $this->modelResultError = "Relationship sums require row or projection results.";
        $expression = "(" . $this->db->compile($aggregate)->sql . ")";
        if ($default !== null) {
            $number = json_encode($default, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $expression = "COALESCE({$expression}, {$number})";
        }
        return $this->scalarColumn($expression, $alias ?? "{$relation}_sum_{$column}");
    }

    /** @param array<string, mixed> $parameters */
    public function scalarColumn(string $expression, string $alias, array $parameters = []): self
    {
        $this->query->scalarColumn($expression, $alias, $parameters);
        return $this;
    }

    /** @param self<*> $query A query selecting one scalar column or aggregate. */
    public function subqueryColumn(self $query, string $alias): self
    {
        if ($query->db->connection() !== $this->db->connection()) {
            throw new InvalidArgumentException("A subquery column must use the same database connection.");
        }

        $child = (clone $query->query)->setModelColumns();
        $columns = $child->getColumns();
        if (count($columns) !== 1 || ($columns[0] instanceof Column && $columns[0]->name === "*")) {
            throw new InvalidArgumentException("A subquery column requires one explicitly selected column or aggregate.");
        }

        $this->query->subqueryColumn($child, $alias);
        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        $this->query->where($column, $value);
        return $this;
    }

    public function whereIf(bool $condition, string $column, mixed $value): self
    {
        return $condition ? $this->where($column, $value) : $this;
    }

    public function whereIfNotNull(string $column, mixed $value): self
    {
        return $this->whereIf($value !== null, $column, $value);
    }

    public function whereFromUntil(string $column, mixed $fromInclusive, mixed $untilExclusive): self
    {
        return $this->where($column, Op::gte($fromInclusive))
            ->where($column, Op::lt($untilExclusive));
    }

    public function exceptId(int|string|null $id): self
    {
        if ($id === null) {
            return $this;
        }

        return $this->where($this->qualifiedPrimaryKey(__FUNCTION__), Op::ne($id));
    }

    private function qualifiedPrimaryKey(string $operation): string
    {
        $from = $this->query->getFrom();
        if ($this->sourceClass === null || $from === null) {
            throw new InvalidArgumentException("{$operation} requires a mapped source.");
        }
        $key = (new EntityMetadataFactory())->for($this->sourceClass)->primaryKey()
            ?? throw new InvalidArgumentException("Model {$this->sourceClass} must define a single primary key.");

        $qualifier = $from->alias ?? $from->name;
        return "{$qualifier}.{$key->columnName}";
    }

    public function orWhere(string $column, mixed $value): self
    {
        $this->query->orWhere($column, $value);
        return $this;
    }

    /** @param list<string> $columns Columns to search for a literal substring. */
    public function whereContainsAny(array $columns, string $term): self
    {
        if ($term === "") {
            return $this;
        }

        return $this->whereAny($columns, Op::contains($term));
    }

    /** @param list<string> $columns Columns to match with the same value or operator. */
    public function whereAny(array $columns, mixed $value): self
    {
        if ($columns === []) {
            throw new InvalidArgumentException("whereAny requires at least one column.");
        }

        return $this->group(static function (WhereGroup $where) use ($columns, $value): void {
            foreach ($columns as $column) {
                $where->orWhere($column, $value);
            }
        });
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function whereExp(string $expression, array $parameters = []): self
    {
        $this->query->whereExp($expression, $parameters);
        return $this;
    }

    /** @param callable(WhereGroup): mixed $builder */
    public function group(callable $builder): self
    {
        $this->query->getWhere()->group($builder, HasManyQuery::predicateBuilder($this->db, $this->sourceClass, $this->query->getFrom()));
        return $this;
    }

    /** @param (callable(WhereGroup): mixed)|null $constraint */
    public function whereHas(string $relation, ?callable $constraint = null): self
    {
        return $this->group(static fn (WhereGroup $group) => $group->whereHas($relation, $constraint));
    }

    public function join(string $table, ?callable $builder = null): self
    {
        $this->query->join($table, $builder);
        return $this;
    }

    public function leftJoin(string $table, ?callable $builder = null): self
    {
        $this->query->leftJoin($table, $builder);
        return $this;
    }

    public function joinRelation(string $relation, ?string $alias = null): self
    {
        return $this->joinBelongsTo($relation, $alias, left: false);
    }

    public function leftJoinRelation(string $relation, ?string $alias = null): self
    {
        return $this->joinBelongsTo($relation, $alias, left: true);
    }

    private function joinBelongsTo(string $relation, ?string $alias, bool $left): self
    {
        $from = $this->query->getFrom();
        if ($this->sourceClass === null || $from === null) {
            throw new InvalidArgumentException("Relationship joins require a mapped source.");
        }

        $metadata = new EntityMetadataFactory();
        $source = $metadata->for($this->sourceClass);
        $sourceAlias = $from->alias ?? $from->name;
        $used = [strtolower($sourceAlias) => true];
        $joined = [];
        foreach ($this->query->getJoins() as $join) {
            $used[strtolower($join->table->alias ?? $join->table->name)] = true;
            if ($join->relationPath !== null) {
                $joined[$join->relationPath][] = $join;
            }
        }

        $path = "";
        $pending = [];
        foreach (explode(".", $relation) as $segment) {
            $path = $path === "" ? $segment : "{$path}.{$segment}";
            $mapping = $source->relation($segment);
            if ($mapping === null || $mapping->type !== RelationMetadata::BELONGS_TO) {
                throw new InvalidArgumentException("Relationship '{$path}' must be a mapped BelongsTo relation.");
            }
            $mapping->assertConnection($this->db, $path);
            $target = $metadata->for($mapping->relatedClass);
            $requestedAlias = $path === $relation ? $alias : null;
            $existing = array_find($joined[$path] ?? [], static fn (Join $join): bool =>
                $requestedAlias === null || $join->table->alias === $requestedAlias);
            if ($existing !== null) {
                if ($path === $relation && $existing->type !== ($left ? Join::LEFT : Join::INNER)) {
                    throw new InvalidArgumentException("Relationship '{$path}' already has a different join type for this alias.");
                }
                $sourceAlias = $existing->table->alias ?? $existing->table->name;
                $source = $target;
                continue;
            }

            $targetAlias = $requestedAlias ?? str_replace(".", "_", $path);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $targetAlias)) {
                throw new InvalidArgumentException("A relationship join alias must be a simple SQL identifier.");
            }
            if (isset($used[strtolower($targetAlias)])) {
                throw new InvalidArgumentException("Join alias '{$targetAlias}' is already in use.");
            }
            $foreignKey = $source->column($mapping->foreignKey);
            $ownerKey = $target->column($mapping->ownerKey);
            if ($foreignKey === null || $ownerKey === null) {
                throw new InvalidArgumentException("Relationship '{$path}' must use mapped keys.");
            }

            $table = "{$target->tableName} {$targetAlias}";
            $join = $left ? Join::left($table, $path) : Join::inner($table, $path);
            $pending[] = $join->on("{$targetAlias}.{$ownerKey->columnName}", Op::column("{$sourceAlias}.{$foreignKey->columnName}"));
            $used[strtolower($targetAlias)] = true;
            $sourceAlias = $targetAlias;
            $source = $target;
        }

        foreach ($pending as $join) {
            $this->query->addJoin($join);
        }
        return $this;
    }

    public function rightJoin(string $table, ?callable $builder = null): self
    {
        $this->query->rightJoin($table, $builder);
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->query->groupBy(...$columns);
        return $this;
    }

    public function having(string $column, mixed $value): self
    {
        $this->query->having($column, $value);
        return $this;
    }

    public function orderBy(string $column, string ...$columns): self
    {
        $this->query->orderBy($column, ...$columns);
        return $this;
    }

    public function orderByDesc(string $column, string ...$columns): self
    {
        $this->query->orderByDesc($column, ...$columns);
        return $this;
    }

    /** @param array<string, mixed> $parameters */
    public function orderByExp(string $expression, array $parameters = [], bool $descending = false): self
    {
        $this->query->orderByExp($expression, $parameters, $descending);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);
        return $this;
    }

    public function with(string ...$relations): self
    {
        if ($this->resultClass === null || $this->resultClass !== $this->sourceClass) {
            throw new InvalidArgumentException("Eager loading requires model results.");
        }
        array_push($this->relations, ...$relations);
        return $this;
    }

    /**
     * @return list<TRow>
     */
    public function all(): array
    {
        $this->validateModelResult();
        return $this->fetchAll($this->query);
    }

    /**
     * @template TResult
     * @param callable(TRow): TResult $mapper
     * @return list<TResult>
     */
    public function map(callable $mapper): array
    {
        return array_map($mapper, $this->all());
    }

    /** @return PageResult<TRow> */
    public function paginate(int $page, int $pageSize): PageResult
    {
        if ($pageSize <= 0) {
            throw new InvalidArgumentException("Page size must be greater than zero.");
        }

        $this->validateModelResult();
        $total = $this->countForPagination();
        $page = PageResult::clampPage($page, $total, $pageSize);
        $query = (clone $this->query)->limit($pageSize)->offset(($page - 1) * $pageSize);

        return new PageResult($this->fetchAll($query), $total, $page, $pageSize);
    }

    /**
     * @return TRow|null
     */
    public function first(): object|array|null
    {
        return $this->fetchFirst($this->query);
    }

    /** @return TRow|null */
    public function find(int|string $id): object|array|null
    {
        $column = $this->qualifiedPrimaryKey(__FUNCTION__);
        $query = clone $this->query;
        $query->getWhere()->groupExisting()->where($column, $id);

        return $this->fetchFirst($query);
    }

    /** @return TRow|null */
    private function fetchFirst(SelectQuery $query): object|array|null
    {
        $this->validateModelResult();
        if ($this->resultClass !== null) {
            $entity = $this->db->firstAs($this->resultClass, $query);
            $row = $entity === null ? null : $this->loadRelation($entity);
        } else {
            $row = $this->db->first($query);
        }

        /** @var TRow|null $row */
        return $row;
    }

    public function scalar(): mixed
    {
        return $this->db->scalar($this->query);
    }

    /** @return ($keyColumn is null ? list<mixed> : array<array-key, mixed>) */
    public function pluck(string $column, ?string $keyColumn = null): array
    {
        $columns = $keyColumn === null ? [$column] : [$keyColumn, $column];
        foreach ($columns as $name) {
            $field = Column::from($name);
            if ($field->name === "" || $field->name === "*" || $field->alias !== null) {
                throw new InvalidArgumentException("Pluck requires a single column name for each argument.");
            }
        }

        $query = (clone $this->query)->clearColumns()->columns(...$columns);
        return $this->db->connection()->pluck($query, keyed: $keyColumn !== null);
    }

    public function exists(): bool
    {
        $command = $this->db->compile($this->query);
        return (bool) $this->db->scalar(
            "SELECT CASE WHEN EXISTS ({$command->sql}) THEN 1 ELSE 0 END",
            $command->parameters
        );
    }

    public function total(string $column = "*"): int
    {
        $query = clone $this->query;
        $query->clearColumns()->count($column);

        return (int) $this->db->scalar($query);
    }

    public function max(string $column): mixed
    {
        $query = (clone $this->query)->clearColumns()->max($column);

        return $this->db->scalar($query);
    }

    public function nextPosition(string $column): int
    {
        $last = (int) ($this->max($column) ?? -1);
        if ($last === PHP_INT_MAX) {
            throw new \OverflowException("The next position for '{$column}' exceeds the integer range.");
        }
        return $last + 1;
    }

    public function sum(string $column): mixed
    {
        $query = (clone $this->query)->clearColumns()->sum($column);

        return $this->db->scalar($query);
    }

    private function countForPagination(): int
    {
        $query = (clone $this->query)->clearOrderBy()->limit(null)->offset(null);
        if (
            $query->getGroupBy() === []
            && $query->getHaving()->isEmpty()
            && !array_any($query->getColumns(), static fn ($column) => $column instanceof SelectExpression && $column->aggregate)
        ) {
            return (int) $this->db->scalar($query->clearColumns()->count());
        }
        return (int) $this->db->scalar($this->countResultRows($query));
    }

    /** @internal Compile one component of Db::counts() on its owning connection. */
    public function countCommand(PdoConnection $connection): Command
    {
        if ($connection !== $this->db->connection()) {
            throw new InvalidArgumentException("All count queries must use the same database connection.");
        }
        $query = (clone $this->query)->clearOrderBy();
        if (
            $query->getGroupBy() === []
            && $query->getHaving()->isEmpty()
            && array_all($query->getColumns(), static fn ($column) => $column instanceof Column && $column->alias === null)
        ) {
            $query->clearColumns();
            if ($query->getLimit() === null && $query->getOffset() === null) {
                return $this->db->compile($query->count());
            }
            $query->scalarColumn("1", "counted_row");
        }
        return $this->countResultRows($query);
    }

    private function countResultRows(SelectQuery $query): Command
    {
        $command = $this->db->compile($query);
        return new Command("SELECT COUNT(*) FROM ({$command->sql}) AS page_rows", $command->parameters);
    }

    /** @return list<TRow> */
    private function fetchAll(SelectQuery $query): array
    {
        if ($this->resultClass !== null) {
            $rows = $this->loadRelations($this->db->allAs($this->resultClass, $query));
        } else {
            $rows = $this->db->all($query);
        }

        /** @var list<TRow> $rows */
        return $rows;
    }

    private function validateModelResult(): void
    {
        if ($this->resultClass === $this->sourceClass && $this->modelResultError !== null) {
            throw new InvalidArgumentException($this->modelResultError);
        }
    }

    /**
     * @param object[] $entities
     * @return object[]
     */
    private function loadRelations(array $entities): array
    {
        if ($this->relations !== []) {
            $this->db->loadMany($entities, ...$this->relations);
        }

        return $entities;
    }

    private function loadRelation(object $entity): object
    {
        if ($this->relations !== []) {
            $this->db->load($entity, ...$this->relations);
        }

        return $entity;
    }
}
