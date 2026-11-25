<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

use InvalidArgumentException;

final readonly class UpsertQuery implements SqlQueryInterface
{
    /** @var list<string> */
    public array $columns;
    /** @var list<array<string, mixed>> */
    public array $rows;
    /** @var list<string> */
    public array $uniqueBy;
    /** @var list<string> */
    public array $update;

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $values
     * @param string|list<string> $uniqueBy
     * @param list<string>|null $update
     */
    public function __construct(public string $table, array $values, string|array $uniqueBy, ?array $update = null)
    {
        $rows = is_array(reset($values)) ? array_values($values) : [$values];
        $first = $rows[0];
        $columns = array_keys($first);
        if ($columns === [] || array_any($columns, static fn ($name) => !is_string($name) || $name === "")) {
            throw new InvalidArgumentException("Upsert rows require named columns.");
        }
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($columns) || array_diff_key($first, $row) !== []) {
                throw new InvalidArgumentException("Every upsert row must supply the same columns.");
            }
        }

        $keys = (array) $uniqueBy;
        $update ??= $columns;
        if ($keys === [] || !array_is_list($keys) || !array_is_list($update)) {
            throw new InvalidArgumentException("Upsert requires a conflict key and lists of column names.");
        }
        foreach ([...$keys, ...$update] as $name) {
            if (!is_string($name) || !array_key_exists($name, $first)) {
                throw new InvalidArgumentException("Upsert conflict and update columns must be present in the rows.");
            }
        }

        $this->columns = $columns;
        $this->rows = $rows;
        $this->uniqueBy = $keys;
        $this->update = $update;
    }
}
