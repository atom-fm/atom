<?php

declare(strict_types=1);

namespace ViewLab;

use Atom\View\Component\ComponentInterface;
use Atom\View\Component\FromContext;
use Atom\View\Html;

final class Label implements ComponentInterface
{
    #[FromContext("label")]
    public string $text;

    public function render(): string
    {
        return Html::tag("strong", content: Html::escape($this->text));
    }
}
