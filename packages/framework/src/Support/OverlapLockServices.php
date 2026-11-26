<?php

declare(strict_types=1);

namespace Atom\Support;

use Atom\Di\Bindings;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderInterface;

/** @internal */
final readonly class OverlapLockServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        if (!$bindings->has(FileOverlapLocks::class)) {
            $bindings->bind(FileOverlapLocks::class)
                ->toFactory(static fn(Injector $injector): FileOverlapLocks => new FileOverlapLocks(
                    $injector->get(Paths::class),
                    "@root/storage/scheduler"
                ))
                ->singleton();
        }
    }
}
