<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorPages\Views;

use Atom\View\Component\AttributeBag;
use Atom\View\Component\TemplateComponent;

final class ErrorDiagnostics extends TemplateComponent
{
    /** @var array<string, int|string> */
    public array $fields;
    public AttributeBag $attributes;
}
