<?php

declare(strict_types=1);

namespace Atom\Profiler;

use Closure;

final class Profile
{
    private static ?Profiler $fallback = null;

    /** @var (Closure(): ?Profiler)|null */
    private static ?Closure $resolver = null;

    /**
     * @internal
     * @param (Closure(): ?Profiler)|null $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function begin(string $name, array $metadata = []): ProfileSpan
    {
        return self::profiler()->begin($name, $metadata);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param array<string, mixed> $metadata
     * @return T
     */
    public static function measure(string $name, callable $callback, array $metadata = []): mixed
    {
        return self::profiler()->measure($name, $callback, $metadata);
    }

    public static function profiler(): Profiler
    {
        return self::$resolver?->__invoke() ?? (self::$fallback ??= new Profiler());
    }

    public static function reset(): void
    {
        self::$fallback = null;
    }
}
