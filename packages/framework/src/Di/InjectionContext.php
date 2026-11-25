<?php

declare(strict_types=1);

namespace Atom\Di;

use Atom\Di\Exception\ScopedDependencyException;

final class InjectionContext
{
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var string[] */
    private array $resolving = [];
    /** @var array<string, true> */
    private array $resolvingSingletons = [];

    public function has(string $token): bool
    {
        return array_key_exists($token, $this->instances);
    }

    public function get(string $token): mixed
    {
        if ($this->has($token)) {
            $this->assertCanResolveScoped($token);
        }
        return $this->instances[$token] ?? null;
    }

    /** @internal */
    public function resolvingSingleton(): ?string
    {
        $token = array_key_last($this->resolvingSingletons);
        return $token === null ? null : (string) $token;
    }

    /** @internal */
    public function assertCanResolveScoped(string $token): void
    {
        $singleton = $this->resolvingSingleton();
        if ($singleton !== null) {
            $path = implode(" -> ", $this->resolvingPath($token));
            throw new ScopedDependencyException(
                "Singleton '{$singleton}' cannot resolve scoped service '{$token}'. Resolution path: {$path}."
            );
        }
    }

    public function set(string $token, mixed $instance): void
    {
        $this->instances[$token] = $instance;
    }

    public function isResolving(string $token): bool
    {
        return in_array($token, $this->resolving, true);
    }

    public function enter(string $token, bool $singleton = false): void
    {
        $this->resolving[] = $token;
        if ($singleton) {
            $this->resolvingSingletons[$token] = true;
        }
    }

    public function leave(string $token): void
    {
        for ($i = count($this->resolving) - 1; $i >= 0; $i--) {
            if ($this->resolving[$i] === $token) {
                array_splice($this->resolving, $i, 1);
                unset($this->resolvingSingletons[$token]);
                return;
            }
        }
    }

    /**
     * @return string[]
     */
    public function resolvingPath(?string $token = null): array
    {
        return $token === null ? $this->resolving : [...$this->resolving, $token];
    }
}
