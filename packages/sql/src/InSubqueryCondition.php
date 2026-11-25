<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final readonly class InSubqueryCondition
{
    public function __construct(public string $boolean, public Column $column, public SelectQuery $query)
    {
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
