<?php

declare(strict_types=1);

namespace Atom\View\Icons;

interface IconResolverInterface
{
    public function resolve(string $name): string;
}
