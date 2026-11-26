<?php

declare(strict_types=1);

namespace Atom\Modules\Icons;

interface IconResolverInterface
{
    public function resolve(string $name): string;
}
