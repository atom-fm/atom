<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final class InsertQuery implements SqlQueryInterface
{
    private ?Table $table = null;
    private bool $defaults = false;
    /** @var array<string, mixed> */
    private array $values = [];

    public function into(string $table): self
    {
        $this->table = Table::from($table);
        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function values(array $values): self
    {
        foreach ($values as $column => $value) {
            $this->set($column, $value);
        }

        return $this;
    }

    public function set(string $column, mixed $value): self
    {
        $this->defaults = false;
        $this->values[$column] = $value;
        return $this;
    }

    /** @internal Used by ORM for entities with only a generated key. */
    public function defaultValues(): self
    {
        $this->values = [];
        $this->defaults = true;

        return $this;
    }

    /** @internal */
    public function usesDefaultValues(): bool
    {
        return $this->defaults;
    }

    public function getTable(): ?Table
    {
        return $this->table;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
