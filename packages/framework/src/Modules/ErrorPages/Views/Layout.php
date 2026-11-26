<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorPages\Views;

use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;
use Atom\View\Component\TemplateFragment;

final class Layout extends TemplateComponent
{
    public ErrorPageView $page;
    public Fragment|TemplateFragment|null $content = null;
}
