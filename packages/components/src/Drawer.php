<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;
use Atom\View\Html;

final class Drawer implements ComponentInterface
{
    public ?Fragment $content = null;

    public ?Fragment $actions = null;

    public ?DrawerModel $model = null;

    public AttributeBag $attributes;

    public ?bool $show = null;

    public string $title = "";

    public string $description = "";

    public string $label = "";

    public string $position = "end";

    public string $size = "md";

    public string $class = "";

    public bool $closable = true;

    public string $closeAction = "closeDrawer";

    public string $closeLabel = "Close drawer";

    public function render(): string
    {
        $this->bindModel();

        if ($this->show !== true) {
            return "";
        }

        $labelId = $this->labelId();
        $descriptionId = $labelId . "-description";
        $drawer = Html::tag("section", Html::mergeAttributes([
            "class" => Html::classes("atom-drawer", $this->class),
            "role" => "dialog",
            "aria-modal" => "true",
            "aria-labelledby" => $this->title === "" ? null : $labelId,
            "aria-describedby" => $this->description === "" ? null : $descriptionId,
            "aria-label" => $this->title === "" ? $this->label : null,
            "data-size" => $this->size,
        ], $this->attributes->all()), $this->body($labelId, $descriptionId));

        return Html::tag("div", [
            "class" => "atom-drawer-backdrop",
            "data-position" => $this->position,
            "atom:transition" => true,
        ], $drawer);
    }

    private function body(string $labelId, string $descriptionId): string
    {
        $content = "";

        if ($this->title !== "" || $this->description !== "" || $this->closable) {
            $content .= Html::tag("header", ["class" => "atom-drawer__header"], $this->header($labelId, $descriptionId));
        }

        $content .= Html::tag("div", ["class" => "atom-drawer__body"], $this->content?->render() ?? "");

        if ($this->actions !== null) {
            $content .= Html::tag("footer", ["class" => "atom-drawer__actions"], $this->actions->render());
        }

        return $content;
    }

    private function header(string $labelId, string $descriptionId): string
    {
        $content = "";
        $heading = "";

        if ($this->title !== "") {
            $heading .= Html::tag("h2", ["id" => $labelId, "class" => "atom-drawer__title"], Html::escape($this->title));
        }

        if ($this->description !== "") {
            $heading .= Html::tag("p", ["id" => $descriptionId, "class" => "atom-drawer__description"], Html::escape($this->description));
        }

        if ($heading !== "") {
            $content .= Html::tag("div", ["class" => "atom-drawer__heading"], $heading);
        }

        if ($this->closable) {
            $content .= Html::tag("button", [
                "type" => "button",
                "class" => "atom-drawer__close",
                "aria-label" => $this->closeLabel,
                "atom:action" => $this->closeAction,
            ], "&times;");
        }

        return $content;
    }

    private function labelId(): string
    {
        return "drawer-" . substr(md5($this->title . "|" . $this->description), 0, 10);
    }

    private function bindModel(): void
    {
        if ($this->model !== null && $this->show === null) {
            $this->show = $this->model->show;
        }
    }
}
