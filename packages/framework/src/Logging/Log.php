<?php

declare(strict_types=1);

namespace Atom\Logging;

use Closure;

final class Log
{
    /** @var (Closure(): ?LoggerInterface)|null */
    private static ?Closure $resolver = null;

    /**
     * @internal
     * @param (Closure(): ?LoggerInterface)|null $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::logger()->info($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::logger()->error($message, $context);
    }

    private static function logger(): LoggerInterface
    {
        return self::$resolver?->__invoke() ?? new NullLogger();
    }
}
