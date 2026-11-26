<?php

declare(strict_types=1);

namespace DiLab;

use Atom\Di\Attributes\Inject;

final readonly class OrderQuote
{
    public function __construct(
        private PriceCatalog $catalog,
        #[Inject("shop.currency")] public string $currency,
    ) {
    }

    public function totalInCents(OrderInput $order): int
    {
        return $this->catalog->unitPriceInCents($order->sku) * $order->quantity;
    }
}
