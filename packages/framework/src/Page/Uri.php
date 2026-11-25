<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Hydrator\Attributes\SourceAttributeInterface;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Uri implements SourceAttributeInterface
{
    public function __construct(public ?string $name = null) {}
    public function source(): string { return "uri"; }
    public function name(): ?string { return $this->name; }
}
