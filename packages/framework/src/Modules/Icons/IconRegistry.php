<?php

declare(strict_types=1);

namespace Atom\Modules\Icons;

use RuntimeException;

final class IconRegistry
{
    /** @var array<string, IconResolverInterface> */
    private array $resolvers = [];

    public function register(string $prefix, IconResolverInterface $resolver): self
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $prefix) !== 1) {
            throw new RuntimeException("Icon prefix '{$prefix}' is invalid.");
        }

        if (isset($this->resolvers[$prefix])) {
            throw new RuntimeException("Icon prefix '{$prefix}' is already registered.");
        }

        $this->resolvers[$prefix] = $resolver;
        return $this;
    }

    public function has(string $prefix): bool
    {
        return isset($this->resolvers[$prefix]);
    }

    public function resolve(string $prefix, string $name): string
    {
        $resolver = $this->resolvers[$prefix] ?? null;
        if ($resolver === null) {
            throw new RuntimeException("Icon prefix '{$prefix}' is not registered.");
        }

        return $resolver->resolve($name);
    }
}
