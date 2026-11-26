<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;
use Atom\View\Html;

final class Navbar implements ComponentInterface
{
    public ?Fragment $brand = null;
    public ?Fragment $content = null;
    public ?Fragment $actions = null;
    public AttributeBag $attributes;
    public string $label = "Application";
    public string $class = "";

    public function render(): string
    {
        $content = "";
        if ($this->brand !== null) {
            $content .= Html::tag("div", ["class" => "atom-navbar__brand"], $this->brand->render());
        }
        if ($this->content !== null) {
            $content .= Html::tag("div", ["class" => "atom-navbar__content"], $this->content->render());
        }
        if ($this->actions !== null) {
            $content .= Html::tag("div", ["class" => "atom-navbar__actions"], $this->actions->render());
        }

        return Html::tag("nav", Html::mergeAttributes([
            "class" => Html::classes("atom-navbar", $this->class),
            "aria-label" => $this->label,
        ], $this->attributes->all()), $content);
    }
}
