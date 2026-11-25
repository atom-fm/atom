<?php

declare(strict_types=1);

namespace Atom\Database;

use Atom\Database\Orm\PdoConnection;
use Atom\Database\Orm\RowHydrator;
use Atom\Database\Orm\ColumnMetadata;
use Atom\Database\Orm\ColumnValueProviderInterface;
use Atom\Database\Sql\Command;
use Atom\Database\Sql\Query;
use Atom\Database\Sql\SqlQueryInterface;
use RuntimeException;

final readonly class Db
{
    public function __construct(
        private PdoConnection $connection,
        private RowHydrator $hydrator = new RowHydrator()
    ) {
    }

    public function connection(): PdoConnection
    {
        return $this->connection;
    }

    public function compile(SqlQueryInterface $query): Command
    {
        return $this->connection->compile($query);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $tableOrClass
     * @return ($tableOrClass is class-string<T> ? DbSelect<T> : DbSelect<array<string, mixed>>)
     */
    public function select(string $tableOrClass, ?string $alias = null): DbSelect
    {
        if (class_exists($tableOrClass)) {
            $metadata = $this->hydrator->metadataFactory()->for($tableOrClass);
            $table = $metadata->tableName;
            $qualifier = $alias ?? $table;
            $select = Query::select($alias === null ? $table : "$table $alias")
                ->setModelColumns(...array_map(
                    static fn (ColumnMetadata $column): string => "{$qualifier}.{$column->columnName}",
                    $metadata->selectableColumns(),
                ));

            /** @var DbSelect<T> $builder */
            $builder = new DbSelect($this, $select, $tableOrClass);
            return $builder;
        }

        /** @var DbSelect<array<string, mixed>> $builder */
        $builder = new DbSelect($this, Query::select($alias === null ? $tableOrClass : "$tableOrClass $alias"));
        return $builder;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function execute(string|SqlQueryInterface|Command $query, array $parameters = []): int
    {
        return $this->connection->execute($query, $parameters);
    }

    public function insert(object $entity): int
    {
        $metadata = $this->hydrator->metadataFactory()->for($entity::class);
        $values = [];
        $autoIncrementKey = null;

        foreach ($metadata->insertableColumns() as $column) {
            $values[$column->columnName] = $this->toDatabaseValue($entity, $this->valueForInsert($entity, $column));
        }

        foreach ($metadata->primaryKeys() as $key) {
            if ($key->autoIncrement) {
                $autoIncrementKey = $key;
                break;
            }
        }

        $affected = $this->execute(Query::insert($metadata->tableName)->values($values));

        if ($autoIncrementKey !== null) {
            $id = $this->connection->lastInsertId();
            if ($id !== false) {
                $autoIncrementKey->setValue($entity, $this->coercePrimaryKey($id, $autoIncrementKey->propertyType));
            }
        }

        if ($affected > 0 && $entity instanceof Model) {
            $entity->syncOriginalValues(array_keys($values));
        }
        return $affected;
    }

    public function update(object $entity): int
    {
        $metadata = $this->hydrator->metadataFactory()->for($entity::class);
        $primaryKey = $metadata->primaryKey() ?? throw new RuntimeException("Entity {$metadata->className} must define a single primary key.");
        $values = $entity instanceof Model ? $entity->changedValues() : [];
        $originals = $entity instanceof Model ? $entity->originalValues() : [];

        foreach ($metadata->updatableColumns() as $column) {
            $name = $column->columnName;
            if ($column->onUpdate !== null) {
                if ($entity instanceof Model && !$column->select
                    && !array_key_exists($name, $originals) && !array_key_exists($name, $values)) {
                    continue;
                }
                $values[$name] = $this->toDatabaseValue($entity, $this->provideValue($entity, $column, $column->onUpdate));
            } elseif (!$entity instanceof Model) {
                $values[$name] = $this->toDatabaseValue($entity, $column->getValue($entity));
            }
        }

        if ($values === []) {
            return 0;
        }

        $affected = $this->execute(
            Query::update($metadata->tableName)
                ->set($values)
                ->where($primaryKey->columnName, $primaryKey->getValue($entity))
        );
        if ($affected > 0 && $entity instanceof Model) {
            $entity->syncOriginalValues(array_keys($values));
        }
        return $affected;
    }

    public function save(object $entity): int
    {
        $metadata = $this->hydrator->metadataFactory()->for($entity::class);
        $primaryKey = $metadata->primaryKey() ?? throw new RuntimeException("Entity {$metadata->className} must define a single primary key.");
        $value = $primaryKey->getValueOrNull($entity);

        return $this->isEmptyPrimaryKey($value) ? $this->insert($entity) : $this->update($entity);
    }

    public function delete(object $entity): int
    {
        $metadata = $this->hydrator->metadataFactory()->for($entity::class);
        $primaryKey = $metadata->primaryKey() ?? throw new RuntimeException("Entity {$metadata->className} must define a single primary key.");

        return $this->execute(
            Query::delete($metadata->tableName)
                ->where($primaryKey->columnName, $primaryKey->getValue($entity))
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public function all(string|SqlQueryInterface|Command $query, array $parameters = []): array
    {
        return $this->connection->all($query, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public function first(string|SqlQueryInterface|Command $query, array $parameters = []): ?array
    {
        return $this->connection->first($query, $parameters);
    }

    /**
     * @param class-string $className
     * @param array<string, mixed> $parameters
     */
    public function firstAs(string $className, string|SqlQueryInterface|Command $query, array $parameters = []): ?object
    {
        $row = $this->first($query, $parameters);
        return $row === null ? null : $this->hydrator->hydrate($className, $row);
    }

    /**
     * @param class-string $className
     * @param array<string, mixed> $parameters
     * @return object[]
     */
    public function allAs(string $className, string|SqlQueryInterface|Command $query, array $parameters = []): array
    {
        return array_map(
            fn(array $row): object => $this->hydrator->hydrate($className, $row),
            $this->all($query, $parameters)
        );
    }

    public function load(object $entity, string ...$relations): object
    {
        $this->loadMany([$entity], ...$relations);
        return $entity;
    }

    /**
     * @param object[] $entities
     * @return object[]
     */
    public function loadMany(array $entities, string ...$relations): array
    {
        if ($entities !== []) {
            $this->loadRelationPaths($entities[0]::class, $entities, $relations);
        }
        return $entities;
    }

    /**
     * @param class-string $className
     * @param object[] $entities
     * @param string[] $paths
     */
    private function loadRelationPaths(string $className, array $entities, array $paths): void
    {
        $branches = [];
        foreach ($paths as $path) {
            $parts = explode(".", $path, 2);
            $branches[$parts[0]] ??= [];
            if (isset($parts[1])) {
                $branches[$parts[0]][] = $parts[1];
            }
        }

        $factory = $this->hydrator->metadataFactory();
        $metadata = $factory->for($className);
        foreach ($branches as $relationName => $nested) {
            $relation = $metadata->relation((string) $relationName);
            if ($relation === null) {
                throw new RuntimeException("Relation '{$relationName}' is not defined on {$className}.");
            }

            $relation->createRelation($factory)->load($this, $entities);
            if ($nested !== []) {
                $this->loadRelationPaths($relation->relatedClass, $relation->relatedModels($entities), $nested);
            }
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function scalar(string|SqlQueryInterface|Command $query, array $parameters = []): mixed
    {
        return $this->connection->scalar($query, $parameters);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->connection->transaction(fn() => $callback($this));
    }

    /**
     * @param array<string, DbSelect<*>> $queries
     * @return array<string, int>
     */
    public function counts(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        $columns = [];
        $parameters = [];
        foreach ($queries as $name => $query) {
            if (!is_string($name) || $name === "" || !$query instanceof DbSelect) {
                throw new \InvalidArgumentException("Counts require a query for each non-empty string key.");
            }
            $index = count($columns);
            $command = $query->countCommand($this->connection)->withParameterPrefix("c{$index}_");
            $columns[] = "({$command->sql}) AS c{$index}";
            $parameters += $command->parameters;
        }

        $row = $this->first("SELECT " . implode(", ", $columns), $parameters);
        return array_combine(array_keys($queries), array_map("intval", array_values($row)));
    }

    private function isEmptyPrimaryKey(mixed $value): bool
    {
        return $value === null || $value === "" || $value === 0 || $value === "0";
    }

    private function coercePrimaryKey(string $value, string $type): mixed
    {
        return match ($type) {
            "int" => (int) $value,
            "float" => (float) $value,
            default => $value,
        };
    }

    private function valueForInsert(object $entity, ColumnMetadata $column): mixed
    {
        return $column->onInsert === null
            ? $column->getValue($entity)
            : $this->provideValue($entity, $column, $column->onInsert);
    }

    /**
     * @param class-string<ColumnValueProviderInterface> $providerClass
     */
    private function provideValue(object $entity, ColumnMetadata $column, string $providerClass): mixed
    {
        $provider = new $providerClass();
        if (!$provider instanceof ColumnValueProviderInterface) {
            throw new RuntimeException("Column value provider '{$providerClass}' must implement ColumnValueProviderInterface.");
        }

        $value = $provider->value($entity, $column);
        $column->setValue($entity, $value);
        return $value;
    }

    private function toDatabaseValue(object $entity, mixed $value): mixed
    {
        return $entity instanceof Model ? $entity::toDatabaseValue($value) : Model::toDatabaseValue($value);
    }
}
