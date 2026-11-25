<?php

declare(strict_types=1);

namespace App\Components;

use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;

final class FeatureGrid extends TemplateComponent
{
    public string $columns = "three";

    public bool $spacious = false;

    public ?Fragment $content = null;

    public function className(): string
    {
        $classes = ["ui-feature-grid"];
        $classes[] = $this->columns === "two"
            ? "ui-feature-grid--two"
            : "ui-feature-grid--three";

        if ($this->spacious) {
            $classes[] = "ui-feature-grid--spacious";
        }

        return implode(" ", $classes);
    }
}
