<?php

declare(strict_types=1);

namespace Atom\Module;

final readonly class StaticFileRouteMetadata
{
    public function __construct(
        public string $directory
    ) {
    }
}
