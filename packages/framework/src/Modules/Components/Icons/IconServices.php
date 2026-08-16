<?php

declare(strict_types=1);

namespace Atom\Modules\Components\Icons;

use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;

final readonly class IconServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        $bindings->bind(IconRegistry::class)->toSelf()->singleton();
    }
}
