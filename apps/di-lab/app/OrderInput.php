<?php

declare(strict_types=1);

namespace DiLab;

final readonly class OrderInput
{
    public function __construct(public string $sku, public int $quantity)
    {
    }
}
