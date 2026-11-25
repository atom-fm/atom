<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final readonly class Sort
{
    public const ASC = "ASC";
    public const DESC = "DESC";

    /** @param array<string, mixed> $parameters */
    private function __construct(
        public Column|string $column,
        public string $direction,
        public array $parameters = [],
    ) {}

    public static function asc(string $column): self
    {
        return new self(Column::from($column), self::ASC);
    }

    public static function desc(string $column): self
    {
        return new self(Column::from($column), self::DESC);
    }

    /** @param array<string, mixed> $parameters */
    public static function expression(string $expression, array $parameters, bool $descending): self
    {
        return new self($expression, $descending ? self::DESC : self::ASC, $parameters);
    }
}
