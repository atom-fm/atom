<?php

declare(strict_types=1);

namespace Showcase\Orders;

use Atom\Validation\Rules\Pattern;
use Atom\Validation\Rules\Required;
use Atom\Validation\ValidateNested;

final class ShippingAddress
{
    #[Required("Enter a street address.")]
    public string $street = "";

    #[Required("Enter a postal code.")]
    #[Pattern("/^[0-9]{5}$/D", "Enter a five-digit postal code.")]
    public string $postalCode = "";

    #[ValidateNested]
    public Unit $unit;

    public function __construct()
    {
        $this->unit = new Unit();
    }
}
