<?php

declare(strict_types=1);

namespace ViewLab;

use Atom\View\Component\ComponentInterface;
use Atom\View\Component\ComponentView;

final class NativeText implements ComponentInterface
{
    public string $text;

    public function render(): string
    {
        return ComponentView::render($this);
    }
}
