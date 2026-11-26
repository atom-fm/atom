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

Rule attributes and `#[ValidateNested]` apply to public instance properties. The validator skips private, protected, and static properties for both objects and arrays validated against a model class.

## Supported API

Validation is in maintenance mode. Routine changes focus on fixes, compatibility, and documentation for the APIs below.

| Area | Contract |
| --- | --- |
| Fluent validation | Build a `Schema` with `Schema::make()`, configure fields with `field()`, and call `validate()` with an array or object. Add rule objects with `Field::rule()`. |
| Attribute validation | Use `Validator::for()->validate($object)` to infer the model class or `Validator::for(Model::class)` to select it explicitly. `#[ValidateNested]` follows marked public instance properties and reports dotted paths. Nested arrays need a declared child class, and nested objects supply their runtime class. |
| Rules | Use the built-in classes under `Atom\Validation\Rules` in schemas or as property attributes. Use `Required` to enforce presence. Implement `ValidationRuleInterface` for custom rules that return a `ValidationError` or `null`. `ValidationContext` provides the field name, original target, and input data. |
| Results | Use `passed()` and `failed()` for status, `first()` and `errorsFor()` for a field, and `all()` for structured errors with codes and parameters. `messages()` groups message strings by field. JSON results contain `valid` and `errors`. |

Existing code can use the compatibility builder `Validation::create()`. Use `Schema::make()` for new schemas. Framework handles request hydration and page integration.

Messages substitute `{name}` placeholders using matching scalar error parameters. The original parameters remain available on the error. Unknown placeholders remain literal.

`Numeric`, `Min`, `Max`, and `Between` reject non-finite numbers, including numeric strings that overflow to infinity.
