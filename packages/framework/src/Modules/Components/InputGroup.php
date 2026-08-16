<?php

declare(strict_types=1);

namespace Atom\Modules\Components;

use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;
use Atom\View\Html;

final class InputGroup implements ComponentInterface
{
    public ?Fragment $content = null;
    public ?Fragment $prefix = null;
    public ?Fragment $suffix = null;
    public AttributeBag $attributes;
    public string $class = "";

    public function render(): string
    {
        $content = $this->adornment($this->prefix, "prefix");
        $content .= $this->content?->render() ?? "";
        $content .= $this->adornment($this->suffix, "suffix");

        return Html::tag("div", Html::mergeAttributes([
            "class" => Html::classes("atom-input-group", $this->class),
        ], $this->attributes->all()), $content);
    }

    private function adornment(?Fragment $fragment, string $position): string
    {
        if ($fragment === null) {
            return "";
        }

        return Html::tag(
            "span",
            ["class" => "atom-input-group__" . $position],
            $fragment->render()
        );
    }
}
