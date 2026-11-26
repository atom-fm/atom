<?php

declare(strict_types=1);

namespace Atom\Tests\View\FormViewFixtures;

use Atom\Page\Form;
use Atom\Validation\Rules\Required;

final class RenderedForm extends Form
{
    #[Required]
    public string $name = "";

    public function heading(): string { return "Form heading"; }
}
