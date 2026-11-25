<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

interface HydrationAwareInterface
{
    public function afterHydration(EntityMetadata $metadata): void;
}
