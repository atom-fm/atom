<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Components\TextField;
use Atom\Page\Form;
use Atom\Page\Page;
use Atom\Validation\Rules\Required;
use Atom\View\Component\AttributeBag;
use PHPUnit\Framework\TestCase;

final class FieldBindingTest extends TestCase
{
    public function testFieldUsesTheSelectedFormsErrors(): void
    {
        $page = new class extends Page {};
        $invalid = new FieldBindingForm();
        $valid = new FieldBindingForm();
        self::assertFalse($invalid->validate());
        $field = new TextField();
        $field->page = $page;
        $field->name = 'name';
        $field->attributes = new AttributeBag();
        $field->model = $invalid;
        self::assertStringContainsString('aria-invalid="true"', $field->render());
        $field->model = $valid;
        self::assertStringNotContainsString('aria-invalid="true"', $field->render());
    }
}

final class FieldBindingForm extends Form
{
    #[Required]
    public string $name = "";
}
