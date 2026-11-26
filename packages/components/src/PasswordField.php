<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\View\Html;

final class PasswordField extends FieldEntry
{
    public ?string $value = null;
    public bool $reveal = true;
    public string $showLabel = "Show password";
    public string $hideLabel = "Hide password";

    public function render(): string
    {
        $label = $this->label === ""
            ? ""
            : Html::tag("label", [
                "class" => "atom-field-label",
                "for" => $this->fieldId(),
            ], Html::escape($this->label));

        return Html::tag(
            "div",
            ["class" => "atom-field"],
            $label . $this->renderControl() . $this->help() . $this->error()
        );
    }

    protected function renderControl(): string
    {
        $input = Html::voidTag("input", Html::mergeAttributes([
            "type" => "password",
            "id" => $this->fieldId(),
            "name" => $this->name,
            "value" => $this->fieldValue(),
            "class" => Html::classes("atom-input", $this->fieldClass()),
            "aria-invalid" => $this->hasError() ? "true" : null,
            "aria-describedby" => $this->describedBy(),
            "data-atom-password-input" => $this->reveal ? true : null,
        ], $this->attributes->all()));

        if (!$this->reveal) {
            return $input;
        }

        $toggle = Html::tag("button", [
            "type" => "button",
            "class" => "atom-password-toggle",
            "aria-controls" => $this->fieldId(),
            "aria-pressed" => "false",
            "aria-label" => $this->showLabel,
            "data-atom-password-toggle" => true,
            "data-show-label" => $this->showLabel,
            "data-hide-label" => $this->hideLabel,
        ], Html::escape($this->showLabel));

        return Html::tag("div", ["class" => "atom-input-group atom-password-field"], $input . $toggle);
    }

    protected function fieldValue(): string
    {
        return $this->value ?? "";
    }
}
