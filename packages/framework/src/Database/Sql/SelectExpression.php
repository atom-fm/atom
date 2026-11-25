<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final readonly class SelectExpression
{
    /** @param array<string, mixed> $parameters */
    private function __construct(
        public string $expression,
        public ?string $alias = null,
        public bool $aggregate = true,
        public array $parameters = [],
    ) {}

    public static function count(string $column = "*", ?string $alias = null): self
    {
        return new self("COUNT({$column})", $alias);
    }

    public static function max(string $column): self
    {
        return new self("MAX({$column})");
    }

    public static function sum(string $column): self
    {
        return new self("SUM({$column})");
    }

    /** @param array<string, mixed> $parameters */
    public static function scalar(string $expression, string $alias, array $parameters = []): self
    {
        return new self($expression, $alias, aggregate: false, parameters: $parameters);
    }
}
