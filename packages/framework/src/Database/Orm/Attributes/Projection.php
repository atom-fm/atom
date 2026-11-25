<?php

declare(strict_types=1);

namespace Atom\Database\Orm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Projection extends Column
{
    public function __construct(?string $name = null)
    {
        parent::__construct(name: $name, select: false, insert: false, update: false);
    }
}
