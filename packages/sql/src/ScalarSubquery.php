<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final readonly class ScalarSubquery
{
    public function __construct(public SelectQuery $query, public string $alias)
    {
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
