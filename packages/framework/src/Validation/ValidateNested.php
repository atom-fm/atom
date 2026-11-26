<?php

declare(strict_types=1);

namespace Atom\Validation;

use Attribute;

/** Apply attribute validation to the object stored in this property. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ValidateNested
{
}
