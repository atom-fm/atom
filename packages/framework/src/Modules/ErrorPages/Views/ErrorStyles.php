<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorPages\Views;

use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Html;
use RuntimeException;

final class ErrorStyles implements ComponentInterface
{
    public AttributeBag $attributes;

    public function render(): string
    {
        $css = @file_get_contents(__DIR__ . "/../Resources/error.css");
        if ($css === false) {
            throw new RuntimeException("Cannot read the error page stylesheet.");
        }

        return Html::tag("style", $this->attributes->all(), $css);
    }
}
