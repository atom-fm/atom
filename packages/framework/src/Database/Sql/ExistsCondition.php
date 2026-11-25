<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final readonly class ExistsCondition
{
    public function __construct(public string $boolean, public SelectQuery $query)
    {
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
