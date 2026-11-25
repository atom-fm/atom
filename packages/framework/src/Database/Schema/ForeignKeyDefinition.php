<?php

declare(strict_types=1);

namespace Atom\Database\Schema;

use InvalidArgumentException;

final readonly class ForeignKeyDefinition
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns
    ) {
        if ($columns === [] || count($columns) !== count($referencedColumns)) {
            throw new InvalidArgumentException("Foreign keys require matching local and referenced columns.");
        }
    }
}
