<?php

declare(strict_types=1);

namespace Showcase\Orders;

use Atom\Validation\Rules\Min;

final class Unit
{
    #[Min(1, "Enter a unit number of at least one.")]
    public ?int $number = null;
}
