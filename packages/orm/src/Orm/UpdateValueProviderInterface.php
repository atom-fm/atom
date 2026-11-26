<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

interface UpdateValueProviderInterface extends ColumnValueProviderInterface
{
    /** Produce the value to bind in an update that has no loaded entity. */
    public function databaseValue(ColumnMetadata $column): mixed;
}
