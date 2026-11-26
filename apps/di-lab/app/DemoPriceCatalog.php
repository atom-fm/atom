<?php

declare(strict_types=1);

namespace DiLab;

use InvalidArgumentException;

final readonly class DemoPriceCatalog implements PriceCatalog
{
    public function unitPriceInCents(string $sku): int
    {
        return match ($sku) {
            "notebook" => 400,
            "pen" => 150,
            default => throw new InvalidArgumentException("Unknown product '{$sku}'."),
        };
    }
}
