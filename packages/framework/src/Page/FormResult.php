<?php

declare(strict_types=1);

namespace Atom\Page;

use InvalidArgumentException;

/** Successful outcome of a form action. */
final readonly class FormResult
{
    private function __construct(public string $message, public ?string $location)
    {
        if ($location !== null && (!str_starts_with($location, "/") || str_starts_with($location, "//")
            || str_contains($location, "\\") || preg_match('/[\x00-\x20\x7f]/', $location) !== 0)) {
            throw new InvalidArgumentException("A form result location must be a local URL starting with '/'.");
        }
    }

    public static function saved(string $message = "", ?string $location = null): self
    {
        return new self($message, $location);
    }
}
