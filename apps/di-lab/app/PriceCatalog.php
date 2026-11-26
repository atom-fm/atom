<?php

declare(strict_types=1);

namespace DiLab;

interface PriceCatalog
{
    public function unitPriceInCents(string $sku): int;
}
