<?php

declare(strict_types=1);

namespace Showcase\Components;

use Atom\View\Component\TemplateComponent;

final class ShowcasePageHeader extends TemplateComponent
{
    public string $title = "";

    public string $description = "";

    public string $badge = "";
}
