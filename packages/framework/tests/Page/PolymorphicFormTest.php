<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Http\Request;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\Page;
use Atom\Page\PageInputHydrator;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use PHPUnit\Framework\TestCase;

final class PolymorphicFormTest extends TestCase
{
    public function testConcreteFormFieldsBindThroughABaseTypedPageProperty(): void
    {
        $page = new PolymorphicFormPage();
        $form = $page->form;
        $request = new Request("POST", "/settings", parsedBody: ["storeName" => "Posted store", "recordId" => "999"]);
        $route = new MatchedRoute(RouteEntry::post("/settings", static fn() => null));

        (new PageInputHydrator())->hydrateForms($page, $request, $route);

        self::assertSame($form, $page->form);
        self::assertSame("Posted store", $form->storeName);
        self::assertSame(7, $form->recordId());
    }
}

final class PolymorphicFormPage extends Page
{
    #[FormModel]
    public Form $form;

    public function __construct() { $this->form = new ConcreteSettingsForm(); }
}

final class ConcreteSettingsForm extends Form
{
    public string $storeName = "Default";
    private int $recordId = 7;

    public function recordId(): int { return $this->recordId; }
}
