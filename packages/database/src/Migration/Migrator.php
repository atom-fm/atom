<?php

declare(strict_types=1);

namespace Atom\Database\Migration;

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\TransactionalDdlDriverInterface;
use Atom\Database\Lock\DatabaseLockManagerInterface;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\SchemaPlan;
use RuntimeException;
use Throwable;

final readonly class Migrator
{
    public function __construct(
        private DatabaseConnection $connection,
        private MigrationRepositoryInterface $repository,
        private DatabaseLockManagerInterface $locks,
        private MigrationDiscovery $discovery,
        private MigrationOptions $options
    ) {
    }

    /**
     * @return MigrationDefinition[]
     */
    public function all(): array
    {
        return $this->discovery->discover($this->options);
    }

    /**
     * @return MigrationDefinition[]
     */
    public function pending(): array
    {
        $applied = array_flip($this->repository->applied());

        return array_values(array_filter(
            $this->all(),
            static fn(MigrationDefinition $migration): bool => !isset($applied[$migration->name])
        ));
    }

    /**
     * @return MigrationStatus[]
     */
    public function status(): array
    {
        $applied = array_flip($this->repository->applied());

        return array_map(
            static fn(MigrationDefinition $migration): MigrationStatus => new MigrationStatus(
                $migration->name,
                isset($applied[$migration->name])
            ),
            $this->all()
        );
    }

    /** @param (callable(): void)|null $beforeMigrations Runs under the migration lock before reading migration history. */
    public function run(?callable $beforeMigrations = null): MigrationRunResult
    {
        $lock = $this->locks->acquire("migrations");
        if ($lock === null) {
            throw new RuntimeException("Migrations are already running.");
        }

        try {
            if ($beforeMigrations !== null) {
                $beforeMigrations();
            }

            $batch = $this->repository->latestBatch() + 1;
            $migrated = [];

            foreach ($this->pending() as $migration) {
                $this->executeMigration($migration, "up", fn() => $this->repository->record($migration->name, $batch));
                $migrated[] = $migration->name;
            }

            return new MigrationRunResult($migrated);
        } finally {
            $lock->release();
        }
    }

    public function rollback(int $steps = 1): MigrationRollbackResult
    {
        $lock = $this->locks->acquire("migrations");
        if ($lock === null) {
            throw new RuntimeException("Migrations are already running.");
        }

        try {
            $latestBatch = $this->repository->latestBatch();
            if ($latestBatch === 0) {
                return new MigrationRollbackResult([]);
            }

            $steps = max(1, $steps);
            $definitions = [];
            foreach ($this->all() as $definition) {
                $definitions[$definition->name] = $definition;
            }

            $rolledBack = [];
            for ($batch = $latestBatch; $batch >= 1 && $steps > 0; $batch--, $steps--) {
                foreach ($this->repository->batch($batch) as $migration) {
                    if (!isset($definitions[$migration])) {
                        throw new RuntimeException("Migration '{$migration}' was recorded but its file was not found.");
                    }

                    $this->executeMigration($definitions[$migration], "down", fn() => $this->repository->delete($migration));
                    $rolledBack[] = $migration;
                }
            }

            return new MigrationRollbackResult($rolledBack);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, string[]>
     */
    public function sql(): array
    {
        $sql = [];

        foreach ($this->pending() as $migration) {
            $sql[$migration->name] = $this->plan($migration, "up")->sql();
        }

        return $sql;
    }

    private function plan(MigrationDefinition $definition, string $method): SchemaPlan
    {
        $migration = $definition->create();
        $schema = new Schema($this->connection->driver()->schemaInspector($this->connection));
        $migration->{$method}($schema);

        return $this->connection->driver()->schemaCompiler()->compile($schema);
    }

    /** @param callable(): void $updateHistory */
    private function executeMigration(MigrationDefinition $definition, string $method, callable $updateHistory): void
    {
        $execute = function () use ($definition, $method, $updateHistory): void {
            foreach ($this->plan($definition, $method)->commands() as $command) {
                $this->connection->execute($command);
            }
            $updateHistory();
        };

        if (!$this->connection->driver() instanceof TransactionalDdlDriverInterface) {
            $execute();
            return;
        }

        if (!$this->connection->pdo()->inTransaction()) {
            $this->connection->transaction($execute);
            return;
        }

        // The caller owns the outer transaction. Undo only this migration if it fails.
        $this->connection->execute("SAVEPOINT atom_migration");
        try {
            $execute();
        } catch (Throwable $error) {
            $this->connection->execute("ROLLBACK TO SAVEPOINT atom_migration");
            throw $error;
        } finally {
            $this->connection->execute("RELEASE SAVEPOINT atom_migration");
        }
    }
}
