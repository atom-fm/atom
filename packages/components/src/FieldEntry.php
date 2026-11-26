<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\Page\Form;
use Atom\Page\Page;
use Atom\Validation\Rules\MaxLength;
use Atom\Validation\ValidationResult;
use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\FromContext;
use Atom\View\Html;
use ReflectionClass;

abstract class FieldEntry implements ComponentInterface
{
    public Page $page;
    public AttributeBag $attributes;
    public string $name;
    public string $label = "";
    public ?string $id = null;
    public ?string $bind = null;
    public string $help = "";
    public string $class = "";
    public string $invalidClass = "is-invalid";
    #[FromContext("model")]
    public mixed $model = null;

    public function render(): string
    {
        return $this->field($this->renderControl() . $this->help() . $this->error());
    }

    abstract protected function renderControl(): string;

    protected function field(string $content): string
    {
        $label = $this->label === "" ? "" : Html::tag("span", ["class" => "atom-field-label"], Html::escape($this->label));

        return Html::tag("label", [
            "class" => "atom-field",
            "for" => $this->fieldId(),
        ], $label . $content);
    }

    private function errors(): ValidationResult
    {
        return $this->model instanceof Form ? $this->model->errors() : $this->page->errors();
    }

    protected function error(): string
    {
        $message = $this->errors()->first($this->boundName());

        return $message === null
            ? ""
            : Html::tag("p", ["id" => $this->fieldId() . "-error", "class" => "atom-field-error"], Html::escape($message));
    }

    protected function help(): string
    {
        return $this->help === ""
            ? ""
            : Html::tag("p", ["id" => $this->fieldId() . "-help", "class" => "atom-field-help"], Html::escape($this->help));
    }

    protected function fieldId(): string
    {
        return $this->id ?? str_replace([".", "[", "]"], "-", $this->name);
    }

    protected function fieldValue(): string
    {
        $property = $this->boundName();
        $field = $this->readField($this->model, $property);
        if (!$field["found"]) {
            $field = $this->readField($this->page, $property);
        }

        return is_scalar($field["value"]) ? (string) $field["value"] : "";
    }

    protected function fieldClass(): string
    {
        return Html::classes($this->class, [$this->invalidClass => $this->hasError()]);
    }

    protected function hasError(): bool
    {
        return $this->errors()->has($this->boundName());
    }

    protected function describedBy(): ?string
    {
        $ids = [];

        if ($this->help !== "") {
            $ids[] = $this->fieldId() . "-help";
        }

        if ($this->hasError()) {
            $ids[] = $this->fieldId() . "-error";
        }

        return $ids === [] ? null : implode(" ", $ids);
    }

    protected function boundName(): string
    {
        return $this->bind ?? rtrim(str_replace(["[", "]"], [".", ""], $this->name), ".");
    }

    protected function inferredMaxLength(): ?int
    {
        if (array_key_exists("maxlength", array_change_key_case($this->attributes->all()))) {
            return null;
        }

        $target = $this->model ?? $this->page;
        $name = $this->boundName();
        if (!is_object($target)) {
            return null;
        }

        $class = new ReflectionClass($target);
        if (!$class->hasProperty($name)) {
            return null;
        }
        $property = $class->getProperty($name);
        if (!$property->isPublic() || $property->isStatic()) {
            return null;
        }

        $rule = $property->getAttributes(MaxLength::class)[0] ?? null;
        $limit = $rule?->newInstance()->value;

        return $limit !== null && $limit >= 0 ? $limit : null;
    }

    /** @return array{found: bool, value: mixed} */
    private function readField(mixed $target, string $path): array
    {
        // An array model may already use a literal dotted key.
        if (is_array($target) && array_key_exists($path, $target)) {
            return ["found" => true, "value" => $target[$path]];
        }

        foreach (explode(".", $path) as $property) {
            if (is_array($target) && array_key_exists($property, $target)) {
                $target = $target[$property];
            } elseif (is_object($target) && isset($target->{$property})) {
                $target = $target->{$property};
            } else {
                return ["found" => false, "value" => null];
            }
        }

        return ["found" => true, "value" => $target];
    }
}
