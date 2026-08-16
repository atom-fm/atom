<?php

declare(strict_types=1);

namespace Atom\Modules\Components;

use Atom\View\Html;

final class FileField extends FieldEntry
{
    protected function renderControl(): string
    {
        return Html::voidTag("input", Html::mergeAttributes([
            "type" => "file",
            "id" => $this->fieldId(),
            "name" => $this->name,
            "class" => Html::classes("atom-file-input", $this->fieldClass()),
            "aria-invalid" => $this->hasError() ? "true" : null,
            "aria-describedby" => $this->describedBy(),
        ], $this->attributes->all()));
    }
}
