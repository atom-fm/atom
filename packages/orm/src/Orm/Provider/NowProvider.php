<?php

declare(strict_types=1);

namespace Atom\Database\Orm\Provider;

use Atom\Database\Orm\ColumnMetadata;
use Atom\Database\Orm\UpdateValueProviderInterface;
use DateTimeImmutable;

final class NowProvider implements UpdateValueProviderInterface
{
    public function value(?object $entity, ColumnMetadata $column): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function databaseValue(ColumnMetadata $column): string
    {
        return $this->value(null, $column)->format("Y-m-d H:i:s");
    }
}
