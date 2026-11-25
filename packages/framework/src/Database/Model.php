<?php

declare(strict_types=1);

namespace Atom\Database;

use Atom\Database\Orm\ColumnMetadata;
use Atom\Database\Orm\DirectUpdateValueProviderInterface;
use Atom\Database\Orm\EntityMetadata;
use Atom\Database\Orm\EntityMetadataFactory;
use Atom\Database\Sql\Query;
use Atom\Database\Sql\UpsertQuery;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

abstract class Model
{
    private const int ID_BATCH_SIZE = 500;

    /** @var array<string, mixed>|null Writable column values observed during hydration or written by this model. */
    private ?array $originalColumnValues = null;
    /** @var array<string, mixed> Initial PHP defaults for columns omitted from the row. */
    private array $unloadedDefaults = [];

    /** @return array<string, mixed> Original storage values keyed by database column name. */
    public function originalValues(): array
    {
        return $this->originalColumnValues ?? [];
    }

    /** @return array<string, mixed> Changed writable values keyed by database column name. */
    public function changedValues(): array
    {
        $baseline = $this->originalValues() + $this->unloadedDefaults;
        $changes = [];
        foreach (self::metadata()->updatableColumns() as $column) {
            if (!$column->isInitialized($this)) {
                continue;
            }
            $name = $column->columnName;
            $value = static::toDatabaseValue($column->getValue($this));
            if (!array_key_exists($name, $baseline) || $baseline[$name] !== $value) {
                $changes[$name] = $value;
            }
        }
        return $changes;
    }

    /** @param list<string> $columnNames Columns actually read or successfully written by a custom persistence path. */
    public function syncOriginalValues(array $columnNames): void
    {
        $firstSnapshot = $this->originalColumnValues === null;
        $this->originalColumnValues ??= [];
        $known = array_fill_keys($columnNames, true);
        foreach (self::metadata()->updatableColumns() as $column) {
            $name = $column->columnName;
            if ((!$firstSnapshot && !isset($known[$name])) || !$column->isInitialized($this)) {
                continue;
            }
            $value = static::toDatabaseValue($column->getValue($this));
            if (isset($known[$name])) {
                $this->originalColumnValues[$name] = $value;
                unset($this->unloadedDefaults[$name]);
            } else {
                $this->unloadedDefaults[$name] = $value;
            }
        }
    }

    public static function toDatabaseValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format("Y-m-d H:i:s") : $value;
    }

    public static function useDb(Db $db): void
    {
        DefaultDatabase::set($db);
    }

    /** @return DbSelect<static> */
    public static function query(?string $alias = null): DbSelect
    {
        return self::db()->select(static::class, $alias);
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::query()->all();
    }

    public static function find(mixed $id, string ...$relations): ?static
    {
        $model = self::queryById($id)->with(...$relations)->first();

        return $model instanceof static ? $model : null;
    }

    public static function existsById(mixed $id): bool
    {
        return self::queryById($id)->exists();
    }

    /** @param array<array-key, mixed> $ids Integer or string primary key values. */
    public static function allIdsExist(array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        $unique = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                return false;
            }
            $unique[$id] = $id;
        }

        $key = self::primaryKey();
        foreach (array_chunk($unique, self::ID_BATCH_SIZE) as $batch) {
            $found = static::query()->where($key->columnName, $batch)->pluck($key->columnName);
            if (count($found) === count($batch)) {
                continue;
            }
            if ($found === []) {
                return false;
            }

            // Distinct input strings can identify the same row under the database's collation.
            foreach (array_diff($batch, $found) as $id) {
                if (($key->propertyType === "int" && is_int($id)) || !static::existsById($id)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return DbSelect<static> */
    private static function queryById(mixed $id): DbSelect
    {
        $key = self::primaryKey();
        return static::query()->where($key->columnName, $id);
    }

    private static function primaryKey(?EntityMetadata $metadata = null): ColumnMetadata
    {
        return ($metadata ?? self::metadata())->primaryKey()
            ?? throw new RuntimeException("Model " . static::class . " must define a single primary key.");
    }

    public static function count(): int
    {
        return static::query()->total();
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $values One row or a batch with the same columns.
     * @param string|list<string> $uniqueBy Conflict key columns.
     * @param list<string>|null $update Columns to replace from the incoming row. Defaults to all supplied columns.
     */
    public static function upsert(array $values, string|array $uniqueBy, ?array $update = null): int
    {
        if ($values === []) {
            return 0;
        }

        return self::db()->execute(new UpsertQuery(self::metadata()->tableName, $values, $uniqueBy, $update));
    }

    public function save(): int
    {
        return self::db()->save($this);
    }

    public function delete(): int
    {
        return self::db()->delete($this);
    }

    /** @param array<string, mixed> $values Database column names and storage values. */
    public static function updateById(int|string $id, array $values): int
    {
        $metadata = self::metadata();
        $key = self::primaryKey($metadata);
        foreach ($metadata->updatableColumns() as $column) {
            $provider = $column->onUpdate;
            if ($provider === null) {
                continue;
            }
            if (!is_subclass_of($provider, DirectUpdateValueProviderInterface::class)) {
                throw new RuntimeException(
                    "{$provider} must implement DirectUpdateValueProviderInterface to update {$column->columnName} by ID. "
                    . "Use save() on a loaded model."
                );
            }
            $values[$column->columnName] = (new $provider())->databaseValue($column);
        }
        if ($values === []) {
            throw new InvalidArgumentException("updateById() requires a value or an onUpdate provider.");
        }

        return self::db()->execute(Query::update($metadata->tableName)->set($values)->where($key->columnName, $id));
    }

    /** @param array<string, mixed> $filters Column names and conditions combined with AND. */
    public static function deleteWhere(array $filters): int
    {
        if ($filters === []) {
            throw new InvalidArgumentException("At least one filter is required to delete model rows.");
        }

        $query = Query::delete(self::metadata()->tableName);
        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }

        return self::db()->execute($query);
    }

    /**
     * @param array<string, mixed> $filters Column names and conditions combined with AND.
     * @param array<string, mixed> $values Column names and database values to write.
     */
    public static function updateWhere(array $filters, array $values): int
    {
        if ($filters === [] || $values === []) {
            throw new InvalidArgumentException("Model updates require at least one filter and one value.");
        }

        $query = Query::update(self::metadata()->tableName)->set($values);
        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }

        return self::db()->execute($query);
    }

    protected static function db(): Db
    {
        return DefaultDatabase::get();
    }

    private static function metadata(): EntityMetadata
    {
        static $factory = new EntityMetadataFactory();
        return $factory->for(static::class);
    }
}
