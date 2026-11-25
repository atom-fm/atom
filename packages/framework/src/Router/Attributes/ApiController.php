<?php

declare(strict_types=1);

namespace Atom\Router\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ApiController extends Controller
{
}
