<?php

declare(strict_types=1);

namespace Atom\View;

use Atom\View\Ast\TemplateNode;
use Closure;
use Throwable;

final class Templates
{
    private static ?TemplateCache $fallback = null;

    /** @var (Closure(): ?TemplateCache)|null */
    private static ?Closure $resolver = null;

    /**
     * @internal
     * @param (Closure(): ?TemplateCache)|null $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param callable(): TemplateNode $factory
     */
    public static function remember(string $path, callable $factory): TemplateNode
    {
        return self::cache()->remember($path, $factory);
    }

    public static function cache(): TemplateCache
    {
        try {
            $cache = self::$resolver?->__invoke();
            if ($cache !== null) {
                return $cache;
            }
        } catch (Throwable) {
        }

        return self::$fallback ??= new TemplateCache();
    }

    public static function reset(): void
    {
        self::$fallback = null;
    }
}
