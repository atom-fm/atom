<?php

declare(strict_types=1);

namespace Showcase\Orders;

use Atom\Page\Form;
use Atom\Validation\Rules\Min;
use Atom\Validation\Rules\Required;
use Atom\Validation\ValidateNested;

final class OrderForm extends Form
{
    #[Required("Enter an order reference.")]
    public string $reference = "";

    #[Required("Enter a quantity.")]
    #[Min(1, "Enter a quantity of at least one.")]
    public ?int $quantity = 1;

    #[ValidateNested]
    public ShippingAddress $address;

    public function __construct()
    {
        $this->address = new ShippingAddress();
    }
}
