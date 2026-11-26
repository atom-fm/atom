<?php

declare(strict_types=1);

namespace ViewLab;

use Atom\View\Component\AttributeBag;
use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;

final class Panel extends TemplateComponent
{
    public string $title;
    public AttributeBag $attributes;
    public ?Fragment $content = null;
    public ?Fragment $footer = null;
}
