<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

use Atom\Database\Exception\UniqueViolationException;
use Atom\Database\Sql\Command;
use Atom\Database\Sql\Compiler\QueryCompilerInterface;
use Closure;
use Atom\Database\Sql\SelectQuery;
use Atom\Database\Sql\SqlQueryInterface;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

class PdoConnection
{
    private ?PDO $pdo = null;

    /** @var Closure(): PDO */
    private readonly Closure $connect;

    /** @param PDO|(Closure(): PDO) $connection */
    public function __construct(PDO|Closure $connection, private readonly QueryCompilerInterface $compiler)
    {
        $this->connect = $connection instanceof PDO ? static fn(): PDO => $connection : $connection;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->connect)();
    }

    public function compile(SqlQueryInterface $query): Command
    {
        return $this->compiler->compile($query);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function execute(string|SqlQueryInterface|Command $query, array $parameters = []): int
    {
        $statement = $this->runStatement($query, $parameters);
        $count = $statement->rowCount();
        $statement->closeCursor();

        return $count;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public function all(string|SqlQueryInterface|Command $query, array $parameters = []): array
    {
        $statement = $this->runStatement($query, $parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public function first(string|SqlQueryInterface|Command $query, array $parameters = []): ?array
    {
        $statement = $this->runStatement($query, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function scalar(string|SqlQueryInterface|Command $query, array $parameters = []): mixed
    {
        $statement = $this->runStatement($query, $parameters);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    /** @return ($keyed is true ? array<array-key, mixed> : list<mixed>) */
    public function pluck(SelectQuery $query, bool $keyed): array
    {
        $statement = $this->runStatement($query, []);
        $values = $statement->fetchAll($keyed ? PDO::FETCH_KEY_PAIR : PDO::FETCH_COLUMN);
        $statement->closeCursor();

        return $values;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo()->lastInsertId($name);
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runStatement(string|SqlQueryInterface|Command $query, array $parameters): PDOStatement
    {
        $statement = $this->prepare($query, $parameters);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            [$sqlState, $driverCode] = array_pad($exception->errorInfo ?? [], 2, null);
            $isUniqueViolation = match ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME)) {
                "sqlite" => in_array((int) $driverCode, [1555, 2067], true),
                "mysql" => (int) $driverCode === 1062,
                "pgsql" => $sqlState === "23505",
                default => false,
            };

            throw $isUniqueViolation ? new UniqueViolationException($exception) : $exception;
        }

        return $statement;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function prepare(string|SqlQueryInterface|Command $query, array $parameters): PDOStatement
    {
        $command = match (true) {
            is_string($query) => new Command($query, $parameters),
            $query instanceof Command => $parameters === []
                ? $query
                : new Command($query->sql, array_replace($query->parameters, $parameters)),
            default => $this->compile($query),
        };
        $statement = $this->pdo()->prepare($command->sql);

        foreach ($command->parameters as $name => $value) {
            $statement->bindValue($name, $value, $this->parameterType($value));
        }

        return $statement;
    }

    private function parameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
