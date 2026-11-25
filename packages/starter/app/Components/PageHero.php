<?php

declare(strict_types=1);

namespace App\Components;

use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;

final class PageHero extends TemplateComponent
{
    public string $eyebrow = "";

    public string $title = "";

    public string $summary = "";

    public string $size = "display";

    public ?Fragment $content = null;

    public function className(): string
    {
        return $this->size === "compact"
            ? "ui-hero ui-hero--compact"
            : "ui-hero";
    }
}
