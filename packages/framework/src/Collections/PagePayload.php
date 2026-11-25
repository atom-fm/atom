<?php

declare(strict_types=1);

namespace Atom\Collections;

use Atom\Api\Attributes\ArrayOf;

/** @internal JSON representation of a PagedCollection. */
final readonly class PagePayload
{
    /** @param list<mixed> $items */
    public function __construct(
        #[ArrayOf]
        public array $items,
        public int $page,
        public int $page_size,
        public int $total,
        public int $total_pages
    ) {
    }
}
