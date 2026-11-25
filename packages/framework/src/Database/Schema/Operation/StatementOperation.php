<?php

declare(strict_types=1);

namespace Atom\Database\Schema\Operation;

final readonly class StatementOperation implements SchemaOperationInterface
{
    public function __construct(public string $sql)
    {
    }
}
