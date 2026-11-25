<?php

declare(strict_types=1);

namespace Atom\Tests\View\FormViewFixtures;

use Atom\Page\Form;

final class CustomForm extends Form
{
    public string $name = "Custom";
    public function heading(): string { return "Custom heading"; }
    public function template(): ?string { return "RenderedForm.atom.html"; }
}
