<?php

declare(strict_types=1);

namespace Atom\Database\Orm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasMany
{
    /**
     * @param class-string $relatedClass
     * @param list<string> $orderBy Mapped child columns in ascending sort order.
     */
    public function __construct(
        public string $relatedClass,
        public string $foreignKey,
        public string $localKey = "id",
        public array $orderBy = [],
    ) {
    }
}
