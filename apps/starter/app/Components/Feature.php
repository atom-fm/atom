<?php

declare(strict_types=1);

namespace App\Components;

use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;

final class Feature extends TemplateComponent
{
    public string $marker = "";

    public string $title = "";

    public bool $prominent = false;

    public ?Fragment $content = null;
}
