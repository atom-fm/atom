<?php

declare(strict_types=1);

namespace Atom\Collections;

/** @internal JSON representation of a PagedCollection. */
final readonly class PagePayload
{
    /** @param list<mixed> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $page_size,
        public int $total,
        public int $total_pages
    ) {
    }
}
