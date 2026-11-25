<?php

declare(strict_types=1);

namespace Atom\Database\Sql\Compiler;

final class SqliteCompiler extends AbstractSqlCompiler
{
    protected function compileLimit(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset !== null) {
            return " LIMIT -1 OFFSET {$offset}";
        }

        return parent::compileLimit($limit, $offset);
    }

    protected function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
