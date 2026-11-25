<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final readonly class Command
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $sql,
        public array $parameters = []
    ) {
    }

    /** @internal Give independently compiled queries disjoint bindings in a combined statement. */
    public function withParameterPrefix(string $prefix): self
    {
        if ($this->parameters === []) {
            return $this;
        }

        $names = [];
        $parameters = [];
        foreach ($this->parameters as $name => $value) {
            $renamed = ":" . $prefix . ltrim($name, ":");
            $names[":" . ltrim($name, ":")] = $renamed;
            $parameters[$renamed] = $value;
        }

        // Skip SQL literals, quoted identifiers, comments and PostgreSQL casts.
        $tokens = <<<'REGEX'
            ~(?:
                '(?:[^']|'')*' | "(?:[^"]|"")*" | `(?:[^`]|``)*` | \[[^\]]*\]
                | (?<delimiter>\$(?:[a-z_][a-z_0-9]*)?\$).*?\k<delimiter>
                | --[^\r\n]* | /\*.*?\*/ | ::
            )(*SKIP)(*F) | :[a-z_][a-z_0-9]*~isx
            REGEX;
        $sql = preg_replace_callback($tokens, static fn (array $match): string => $names[$match[0]] ?? $match[0], $this->sql);
        return new self($sql ?? throw new \RuntimeException("Could not scope query parameters."), $parameters);
    }
}
