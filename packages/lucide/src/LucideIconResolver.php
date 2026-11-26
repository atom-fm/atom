<?php

declare(strict_types=1);

namespace Atom\Lucide;

use Atom\View\Icons\IconResolverInterface;
use RuntimeException;

final readonly class LucideIconResolver implements IconResolverInterface
{
    public function resolve(string $name): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            throw new RuntimeException("Lucide icon name '{$name}' is invalid.");
        }

        $path = dirname(__DIR__) . "/resources/icons/{$name}.svg";
        if (!is_file($path)) {
            throw new RuntimeException("Lucide icon '{$name}' was not found.");
        }

        $svg = file_get_contents($path);
        if ($svg === false) {
            throw new RuntimeException("Lucide icon '{$name}' could not be read.");
        }

        $svg = trim(preg_replace('/^\s*<\?xml[^>]*>\s*/i', "", $svg) ?? $svg);
        $svg = trim(preg_replace('/^\s*<!--.*?-->\s*/s', "", $svg) ?? $svg);
        if (!str_starts_with(strtolower($svg), "<svg")) {
            throw new RuntimeException("Lucide icon '{$name}' does not contain SVG markup.");
        }

        return $svg;
    }
}
