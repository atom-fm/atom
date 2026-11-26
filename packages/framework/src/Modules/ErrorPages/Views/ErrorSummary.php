<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorPages\Views;

use Atom\Modules\ErrorPages\ErrorPage;
use Atom\View\Component\AttributeBag;
use Atom\View\Component\TemplateComponent;

final class ErrorSummary extends TemplateComponent
{
    public ErrorPage $page;
    public AttributeBag $attributes;
}
