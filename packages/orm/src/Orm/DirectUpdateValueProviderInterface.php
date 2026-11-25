<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

interface DirectUpdateValueProviderInterface extends ColumnValueProviderInterface
{
    /** Produce the value to bind in an update that has no loaded entity. */
    public function databaseValue(ColumnMetadata $column): string|int|float|bool|null;
}
