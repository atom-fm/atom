<?php

declare(strict_types=1);

namespace Atom\Profiler;

final class ProfileSpan
{
    private ?float $endedAt = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly Profiler $profiler,
        public readonly string $name,
        public readonly float $startedAt,
        public readonly array $metadata = []
    ) {
    }

    public function end(): self
    {
        if ($this->endedAt !== null) {
            return $this;
        }

        $this->endedAt = hrtime(true) / 1_000_000_000;
        $this->profiler->record($this);

        return $this;
    }

    public function endedAt(): ?float
    {
        return $this->endedAt;
    }

    public function durationMs(): float
    {
        $endedAt = $this->endedAt ?? hrtime(true) / 1_000_000_000;

        return ($endedAt - $this->startedAt) * 1000;
    }
}
