<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Validation\ValidationError;
use Atom\Validation\ValidationResult;
use Atom\Validation\Validator;

/** A page form owns its validation state; persistence belongs to the application. */
abstract class Form
{
    private ?ValidationResult $validation = null;
    private ?ValidationResult $inputErrors = null;

    public function template(): ?string
    {
        return null;
    }

    public function validate(): bool
    {
        $this->validation = Validator::for()->validate($this);
        return $this->errors()->passed();
    }

    public function errors(): ValidationResult
    {
        $validation = $this->validation ??= ValidationResult::valid();

        return $this->inputErrors === null
            ? $validation
            : ValidationResult::valid()->merge($this->inputErrors)->merge($validation);
    }

    /** @internal */
    final public function addInputErrors(ValidationResult $errors): void
    {
        ($this->inputErrors ??= ValidationResult::valid())->merge($errors);
    }

    protected function setValidation(ValidationResult $validation): void
    {
        $this->validation = $validation;
    }

    protected function addError(string $field, string $message, string $code = "invalid"): void
    {
        ($this->validation ??= ValidationResult::valid())->add(new ValidationError($field, $message, $code));
    }
}
