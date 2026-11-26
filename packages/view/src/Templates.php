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

    /** @var (Closure(string, string, Closure(): TemplateNode): TemplateNode)|null */
    private static ?Closure $componentParser = null;

    /**
     * @internal
     * @param Closure(string, string, Closure(): TemplateNode): TemplateNode $parser
     */
    public static function instrumentComponentParsingUsing(Closure $parser): void
    {
        self::$componentParser = $parser;
    }

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

    /** @param Closure(): TemplateNode $parse */
    public static function rememberComponent(string $path, string $component, Closure $parse): TemplateNode
    {
        return self::remember($path, static function () use ($path, $component, $parse): TemplateNode {
            return self::$componentParser?->__invoke($path, $component, $parse) ?? $parse();
        });
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
