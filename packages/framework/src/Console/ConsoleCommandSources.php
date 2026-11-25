<?php

declare(strict_types=1);

namespace Atom\Console;

use Closure;

/**
 * @implements \IteratorAggregate<int, ConsoleCommandSource>
 */
final class ConsoleCommandSources implements \IteratorAggregate
{
    /** @var ConsoleCommandSource[] */
    private array $sources = [];
    /** @var list<Closure(ConsoleCommandSource): void> */
    private array $listeners = [];

    public function add(string $directory, string $namespace): self
    {
        $source = new ConsoleCommandSource($directory, $namespace);
        foreach ($this->listeners as $listener) {
            $listener($source);
        }
        $this->sources[] = $source;
        return $this;
    }

    /** @internal Observe sources added after the console's initial discovery. */
    public function onAdded(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @return ConsoleCommandSource[]
     */
    public function all(): array
    {
        return $this->sources;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->sources);
    }
}
