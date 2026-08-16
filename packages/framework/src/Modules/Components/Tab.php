<?php

declare(strict_types=1);

namespace Atom\Modules\Components;

use Atom\Support\Paths;
use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;
use Atom\View\Html;

final class Tab implements ComponentInterface
{
    public ?Fragment $content = null;
    public AttributeBag $attributes;
    public string $name = "";
    public string $label = "";
    public string $text = "";
    public string $icon = "";
    public string $badge = "";
    public string $badgeVariant = "neutral";
    public string $href = "";
    public string $action = "";
    public string $class = "";

    public function __construct(private ?Paths $paths = null)
    {
    }

    public function render(): string
    {
        return $this->renderContent();
    }

    public function renderHeader(bool $active): string
    {
        $tag = $this->href === "" ? "button" : "a";

        return Html::tag($tag, Html::mergeAttributes([
            "href" => $this->href,
            "type" => $tag === "button" ? "button" : null,
            "class" => Html::classes("atom-tab", ["is-active" => $active], $this->class),
            "aria-current" => $active ? "page" : null,
            "data-tab" => $this->name,
            "atom:action" => $this->action,
        ], $this->attributes->all()), $this->headerContent());
    }

    public function renderContent(): string
    {
        if ($this->content !== null) {
            return $this->content->render();
        }

        return Html::escape($this->text);
    }

    private function headerText(): string
    {
        if ($this->label !== "") {
            return $this->label;
        }

        if ($this->text !== "") {
            return $this->text;
        }

        return $this->name;
    }

    private function headerContent(): string
    {
        $text = $this->headerText();
        if ($this->icon === "" && $this->badge === "") {
            return Html::escape($text);
        }

        $content = "";
        if ($this->icon !== "") {
            $icon = Icon::from($this->icon, $this->paths);
            $icon->class = "atom-tab__icon";
            $icon->size = "sm";
            $content .= $icon->render();
        }

        $content .= Html::tag("span", ["class" => "atom-tab__label"], Html::escape($text));

        if ($this->badge !== "") {
            $badge = new Badge();
            $badge->attributes = new AttributeBag();
            $badge->class = "atom-tab__badge";
            $badge->text = $this->badge;
            $badge->variant = $this->badgeVariant;
            $badge->appearance = "soft";
            $badge->size = "sm";
            $content .= $badge->render();
        }

        return $content;
    }
}
