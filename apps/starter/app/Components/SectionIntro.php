<?php

declare(strict_types=1);

namespace App\Components;

use Atom\View\Component\TemplateComponent;

final class SectionIntro extends TemplateComponent
{
    public string $id = "";

    public string $eyebrow = "";

    public string $title = "";
}
