# Atom Validation

`atom-fm/validation` validates arrays with fluent schemas and objects with PHP attributes. It runs in plain PHP scripts as well as in Atom applications.

Save this example beside your Composer `vendor` directory and run it with PHP. The nested rules report errors using dotted property paths.

```php
<?php

declare(strict_types=1);

use Atom\Validation\Rules\Required;
use Atom\Validation\ValidateNested;
use Atom\Validation\Validator;

require __DIR__ . "/vendor/autoload.php";

final class ShippingAddress
{
    #[Required("Enter a postal code.")]
    public string $postalCode = "";
}

final class Order
{
    public function __construct(#[ValidateNested] public ShippingAddress $address)
    {
    }
}

$order = new Order(new ShippingAddress());
$validator = Validator::for();

echo $validator->validate($order)->first("address.postalCode"), PHP_EOL;

$order->address->postalCode = "71000";
echo $validator->validate($order)->passed() ? "Order valid" : "Order invalid", PHP_EOL;
```

From the monorepo root, run `composer install:validation` and `composer validation:check` to develop the package.

To validate arrays against model attributes, use `Validator::for(Order::class)`. A child array under a `#[ValidateNested]` property uses that property's declared class to look up its rules. Child objects use their runtime class. Both report dotted paths such as `address.postalCode`.
