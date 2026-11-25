<?php

declare(strict_types=1);

namespace Atom\Tests\View;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Modules\Components\Components;
use Atom\Page\Page;
use Atom\Security\CsrfTokenManager;
use Atom\Security\CsrfTokenManagerInterface;
use Atom\Session\ArraySession;
use Atom\Tests\View\FormViewFixtures\CustomForm;
use Atom\Tests\View\FormViewFixtures\RenderedForm;
use Atom\View\Component\ComponentRegistry;
use Atom\View\Component\InjectorComponentFactory;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;
use Atom\View\Render\ViewRenderException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/FormViewFixtures/RenderedForm.php";
require_once __DIR__ . "/FormViewFixtures/CustomForm.php";

final class FormViewTest extends TestCase
{
    public function testRendersAdjacentTemplateWithModelErrorsCsrfAndPageContext(): void
    {
        $form = new RenderedForm();
        $form->validate();
        $html = $this->render('<FormView :form="$form" submit="save" class="editor" data-editor="details" /><p>{{ $this->cancelUrl() }}</p>', $form);
        self::assertStringContainsString('<h2>Form heading</h2>', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('id="name-error"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('name="_action" value="save"', $html);
        self::assertStringContainsString('atom:submit="save"', $html);
        self::assertStringContainsString('class="atom-form editor"', $html);
        self::assertStringContainsString('data-editor="details"', $html);
        self::assertStringContainsString('href="/catalog"', $html);
        self::assertStringContainsString('<p>/catalog</p>', $html);
        self::assertSame(1, substr_count($html, '<form '));
        $form->name = '<script>posted</script>';
        $form->validate();
        $html = $this->render('<FormView :form="$form" submit="save" />', $form);
        self::assertStringContainsString('value="&lt;script&gt;posted&lt;/script&gt;"', $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    public function testSupportsCustomTemplatesAndGetForms(): void
    {
        $html = $this->render('<FormView :form="$form" method="get" action="/search" />', new CustomForm());
        self::assertStringContainsString('Custom heading', $html);
        self::assertStringContainsString('value="Custom"', $html);
        self::assertStringContainsString('method="get" action="/search"', $html);
        self::assertStringNotContainsString('name="_token"', $html);
        self::assertStringNotContainsString('name="_action"', $html);
    }

    public function testMissingTemplateReportsTheFormClass(): void
    {
        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('Cannot find form template');
        $this->render('<FormView :form="$form" />', new MissingViewForm());
    }

    public function testRejectsObjectsWithoutTheFormContract(): void
    {
        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('FormView requires a form');
        $this->render('<FormView :form="$form" />', new \stdClass());
    }

    private function render(string $source, object $form): string
    {
        $context = new InjectionContext();
        $context->set(CsrfTokenManagerInterface::class, new CsrfTokenManager(new ArraySession()));
        $registry = (new ComponentRegistry())->import(Components::definitions());
        $renderer = new ViewRenderer(components: $registry, componentFactory: new InjectorComponentFactory(new Injector(), $context));
        $page = new FormViewOwnerPage();
        return $renderer->render((new ViewParser())->parse($source), ['page' => $page, 'this' => $page, 'form' => $form]);
    }
}

final class MissingViewForm extends \Atom\Page\Form {}

final class FormViewOwnerPage extends Page
{
    public function cancelUrl(): string { return '/catalog'; }
}
