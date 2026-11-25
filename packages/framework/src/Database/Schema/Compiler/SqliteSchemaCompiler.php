<?php

declare(strict_types=1);

namespace Atom\Database\Schema\Compiler;

use Atom\Database\Schema\Table;
use LogicException;

final class SqliteSchemaCompiler extends AbstractSchemaCompiler
{
    protected function compileAlterTable(Table $table): array
    {
        if ($table->foreignKeys() !== []) {
            throw new LogicException("Adding a foreign key to an existing SQLite table requires rebuilding the table.");
        }

        return parent::compileAlterTable($table);
    }

    protected function name(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    protected function autoIncrement(): string
    {
        return "AUTOINCREMENT";
    }

    protected function stringType(int $length): string
    {
        return "VARCHAR({$length})";
    }

    protected function textType(): string
    {
        return "TEXT";
    }

    protected function integerType(): string
    {
        return "INTEGER";
    }

    protected function bigIntegerType(): string
    {
        return "INTEGER";
    }

    protected function floatType(): string
    {
        return "REAL";
    }

    protected function decimalType(int $precision, int $scale): string
    {
        return "NUMERIC({$precision}, {$scale})";
    }

    protected function booleanType(): string
    {
        return "INTEGER";
    }

    protected function dateType(): string
    {
        return "DATE";
    }

    protected function dateTimeType(): string
    {
        return "DATETIME";
    }

    protected function timestampType(): string
    {
        return "DATETIME";
    }

    protected function jsonType(): string
    {
        return "TEXT";
    }

    protected function binaryType(): string
    {
        return "BLOB";
    }

    protected function uuidType(): string
    {
        return "CHAR(36)";
    }
}
