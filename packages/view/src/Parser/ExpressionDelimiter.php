<?php

declare(strict_types=1);

namespace Atom\View\Parser;

/** @internal */
final class ExpressionDelimiter
{
    public static function findEnd(string $source, int $start): ?int
    {
        return self::scanCode($source, $start);
    }

    private static function scanCode(string $source, int $start, bool $insideString = false): ?int
    {
        $length = strlen($source);
        $braceDepth = $insideString ? 1 : 0;

        for ($offset = $start; $offset < $length; $offset++) {
            $char = $source[$offset];
            $next = $source[$offset + 1] ?? "";

            if ($char === '"' || $char === "'" || $char === "`") {
                $end = self::scanString($source, $offset + 1, $char);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
            } elseif ($char === "<" && substr($source, $offset, 3) === "<<<") {
                $end = self::scanHeredoc($source, $offset);
                if ($end !== null) {
                    $offset = $end - 1;
                }
            } elseif ($char === "/" && $next === "*") {
                $end = strpos($source, "*/", $offset + 2);
                if ($end === false) {
                    return null;
                }
                $offset = $end + 1;
            } elseif (($char === "/" && $next === "/") || ($char === "#" && $next !== "[")) {
                $end = $offset + strcspn($source, "\r\n", $offset);
                // A line comment at template level can end with the expression.
                $delimiter = $braceDepth === 0 ? strpos($source, "}}", $offset) : false;
                if ($delimiter !== false && $delimiter < $end) {
                    return $delimiter;
                }
                $offset = $end;
            } elseif ($char === "{") {
                $braceDepth++;
            } elseif ($char === "}") {
                if ($braceDepth === 0 && $next === "}") {
                    return $offset;
                }
                if ($braceDepth > 0) {
                    $braceDepth--;
                    if ($insideString && $braceDepth === 0) {
                        return $offset;
                    }
                }
            }
        }

        return null;
    }

    private static function scanHeredoc(string $source, int $start): ?int
    {
        $openingPattern = '~\G<<<[ \t]*(["\']?)([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\1[ \t]*(?:\r\n?|\n)~';
        if (preg_match($openingPattern, $source, $opening, offset: $start) !== 1) {
            return null;
        }

        $label = preg_quote($opening[2], "~");
        $body = $start + strlen($opening[0]);
        $closingPattern = '~(?:\G|(?<=[\r\n]))[ \t]*' . $label . '(?![a-zA-Z0-9_\x80-\xff])~';
        if (preg_match($closingPattern, $source, $closing, PREG_OFFSET_CAPTURE, $body) !== 1) {
            return null;
        }

        return $closing[0][1] + strlen($closing[0][0]);
    }

    private static function scanString(string $source, int $start, string $quote): ?int
    {
        $length = strlen($source);

        for ($offset = $start; $offset < $length; $offset++) {
            $char = $source[$offset];
            $next = $source[$offset + 1] ?? "";
            if ($char === "\\") {
                $offset++;
            } elseif ($char === $quote) {
                return $offset;
            } elseif ($quote !== "'" && (($char === "{" && $next === "$") || ($char === "$" && $next === "{"))) {
                $brace = $char === "{" ? $offset : $offset + 1;
                $end = self::scanCode($source, $brace + 1, insideString: true);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
            }
        }

        return null;
    }
}
