<?php

declare(strict_types=1);

namespace Atom\Database\Sql\Compiler;

use Atom\Database\Sql\UpsertQuery;

class MySqlCompiler extends AbstractSqlCompiler
{
    protected function compileUpsertConflict(UpsertQuery $query): string
    {
        $assignments = [];
        foreach ($query->update as $column) {
            $name = $this->quoteIdentifier($column);
            $assignments[] = "{$name} = VALUES({$name})";
        }
        return " ON DUPLICATE KEY UPDATE " . implode(", ", $assignments);
    }

    protected function quoteIdentifier(string $name): string
    {
        return "`" . str_replace("`", "``", $name) . "`";
    }

    protected function compileLimit(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset !== null) {
            // MySQL uses the maximum unsigned BIGINT as its unbounded row count.
            return " LIMIT 18446744073709551615 OFFSET {$offset}";
        }

        return parent::compileLimit($limit, $offset);
    }

    protected function compileDeleteLimit(?int $limit): string
    {
        return $limit === null ? "" : " LIMIT {$limit}";
    }
}
