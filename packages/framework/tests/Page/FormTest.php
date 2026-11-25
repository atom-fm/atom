<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Modules\Components\TextField;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\FormResult;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageActionHandler;
use Atom\Page\PageInputHydrator;
use Atom\Page\PageRenderer;
use Atom\Page\PageRouteMetadata;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use Atom\Session\ArraySession;
use Atom\Session\SessionInterface;
use Atom\Validation\Rules\Required;
use Atom\View\Component\AttributeBag;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    public function testFormsRetainErrorsAndFieldsUseTheirOwnModel(): void
    {
        $page = new FormOwnerPage();
        self::assertFalse($page->first->validate());
        self::assertTrue($page->errors()->has('name'));
        self::assertCount(1, $page->errors());
        self::assertCount(1, $page->errors());
        $field = new TextField();
        $field->page = $page;
        $field->name = 'name';
        $field->attributes = new AttributeBag();
        $field->model = $page->first;
        self::assertStringContainsString('aria-invalid="true"', $field->render());
        $field->model = $page->second;
        self::assertStringNotContainsString('aria-invalid="true"', $field->render());
        $page->first->name = 'Updated';
        self::assertTrue($page->first->validate());
        self::assertTrue($page->errors()->passed());
    }

    public function testFormHydrationCannotOverwritePrivateContextOrErrors(): void
    {
        $page = new FormOwnerPage();
        $page->first->validate();
        $route = new MatchedRoute(RouteEntry::post('/form', static fn() => null));
        (new PageInputHydrator())->hydrate($page, new Request('POST', '/form', parsedBody: [
            'name' => 'Posted', 'recordId' => '999', 'validation' => 'forged',
        ]), $route);
        self::assertSame('Posted', $page->first->name);
        self::assertSame(7, $page->first->recordId());
        self::assertTrue($page->first->errors()->has('name'));
    }

    public function testNullableFormsStayAbsentAndConstructorInitializedFormsReceivePostedValues(): void
    {
        $page = new FormOwnerPage();
        $route = new MatchedRoute(RouteEntry::post('/form', static fn() => null));
        $request = new Request('POST', '/form', parsedBody: ['name' => 'Posted value']);
        $hydrator = new PageInputHydrator();
        $hydrator->hydrate($page, $request, $route);
        self::assertNull($page->optional);
        $page->optional = new ConstructorInitializedForm('Model default');
        $hydrator->hydrate($page, $request, $route);
        self::assertSame('Posted value', $page->optional->name);
    }

    public function testDirectFormSaveRendersErrorsAndPreservesInputOnFailure(): void
    {
        $result = $this->submitForm(['name' => '', 'notes' => 'Keep this input']);
        self::assertIsString($result);
        self::assertStringContainsString('Keep this input', $result);
        self::assertStringContainsString('The field is required.', $result);
        self::assertStringNotContainsString('Form saved', $result);
    }

    public function testDirectFormSaveCallsOneSuccessHookAndCanRedirect(): void
    {
        $result = $this->submitForm(['name' => 'Valid', 'notes' => 'redirect']);
        self::assertInstanceOf(Response::class, $result);
        self::assertSame(303, $result->getStatus());
    }

    public function testDirectFormSaveCanRenderAfterSuccess(): void
    {
        $result = $this->submitForm(['name' => 'Valid', 'notes' => 'stay']);
        self::assertIsString($result);
        self::assertStringContainsString('Form saved', $result);
        self::assertStringNotContainsString('The field is required.', $result);
    }

    public function testDirectSubmissionStillRequiresAnAttributedAction(): void
    {
        $this->expectException(\Atom\Page\PageActionException::class);
        $this->submitForm(['name' => 'Valid'], 'form.validate');
    }

    public function testFormResultWithoutLocationRefreshesTheSubmittedUri(): void
    {
        $session = new ArraySession();
        $response = $this->submitForm(['name' => 'Valid', 'notes' => 'posted value'], pageClass: FormResultPage::class, query: ['q' => 'a b'], session: $session);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(303, $response->getStatus());
        self::assertSame('/form?q=a%20b', $response->headers()->get('Location'));
        $renderer = $this->destinationRenderer('/form', ['q' => 'a b'], $session);
        $html = $renderer->render(FormResultPage::class);
        self::assertStringContainsString('Result saved', $html);
        self::assertStringNotContainsString('posted value', $html);
        self::assertStringNotContainsString('Result saved', $renderer->render(FormResultPage::class));

        $response = $this->submitForm(['name' => 'Valid', 'notes' => 'posted value'], pageClass: FormResultPage::class, query: ['q' => 'a b'], enhanced: true);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertSame('/form?q=a%20b', $response->headers()->get('X-Atom-Uri'));
        self::assertStringContainsString('Result saved', $response->getContent());
        self::assertStringNotContainsString('posted value', $response->getContent());
    }

    public function testFormResultCanRedirectAnEnhancedRequestToAnotherPage(): void
    {
        $session = new ArraySession();
        $response = $this->submitForm(['name' => 'Valid', 'notes' => 'redirect'], pageClass: FormResultPage::class, enhanced: true, session: $session);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(303, $response->getStatus());
        self::assertSame('/done?q=a+b', $response->headers()->get('Location'));
        self::assertNull($response->headers()->get('X-Atom-Uri'));
        self::assertStringNotContainsString('Result saved', $this->destinationRenderer('/form', [], $session)->render(FormResultPage::class));
        self::assertStringContainsString('Result saved', $this->destinationRenderer('/done', ['q' => 'a b'], $session)->render(SubmittedFormPage::class));
    }

    public function testPageActionControlsTheResponseWhenItCallsAForm(): void
    {
        $result = $this->submitForm(['name' => 'Valid', 'notes' => 'redirect'], action: 'coordinate', pageClass: FormResultPage::class, enhanced: true);
        self::assertIsString($result);
        self::assertStringContainsString('Page coordinated the save', $result);
        self::assertStringNotContainsString('Result saved', $result);
    }

    public function testUnavailableDirectFormKeepsTheCurrentPage(): void
    {
        foreach ([false, true] as $enhanced) {
            $html = $this->submitForm(['name' => 'Valid'], action: 'unavailable.save', pageClass: FormResultPage::class, enhanced: $enhanced);
            self::assertIsString($html);
            self::assertStringNotContainsString('Result saved', $html);
        }
    }

    /** @param class-string<Page> $pageClass */
    private function submitForm(array $body, string $action = 'form.save', string $pageClass = SubmittedFormPage::class, array $query = [], bool $enhanced = false, ?ArraySession $session = null): mixed
    {
        $router = new \Atom\Router\Router();
        (new \Atom\Page\PageRouteRegistrar())->register($router, [
            new \Atom\Page\PageDescriptor('/form', $pageClass),
        ]);
        $match = (new \Atom\Router\RouteMatcher($router))->match('POST', '/form');
        self::assertTrue($match->isFound());
        $request = new Request('POST', '/form', $query, [...$body, '_action' => $action], headers: $enhanced ? ['X-Requested-With' => 'Atom'] : []);
        $context = new InjectionContext();
        $context->set(Request::class, $request);
        $context->set(SessionInterface::class, $session ?? new ArraySession());
        $injector = new Injector();
        return (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $match->matchedRoute, $request);
    }

    private function destinationRenderer(string $path, array $query, ArraySession $session): PageRenderer
    {
        $context = new InjectionContext();
        $context->set(Request::class, new Request('GET', $path, $query));
        $context->set(MatchedRoute::class, new MatchedRoute(RouteEntry::get($path, static fn() => null)));
        $context->set(SessionInterface::class, $session);

        return new PageRenderer(new Injector(), $context);
    }

    public function testPostStopsWhenGetReturnsAResponse(): void
    {
        $route = new MatchedRoute(RouteEntry::post('/missing', static fn() => null)
            ->metadata(new PageRouteMetadata(StoppedFormPage::class)));
        $request = new Request('POST', '/missing', parsedBody: ['_action' => 'save']);
        $injector = new Injector();
        $context = new InjectionContext();
        $context->set(Request::class, $request);
        $result = (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $route, $request);
        self::assertInstanceOf(Response::class, $result);
        self::assertSame(404, $result->getStatus());
    }
}

final class FormOwnerPage extends Page
{
    #[FormModel]
    public ExampleOwnedForm $first;
    #[FormModel]
    public ExampleOwnedForm $second;
    #[FormModel]
    public ?ConstructorInitializedForm $optional = null;

    public function __construct()
    {
        $this->first = new ExampleOwnedForm();
        $this->second = new ExampleOwnedForm();
    }
}

final class ExampleOwnedForm extends Form
{
    #[Required]
    public string $name = '';
    private int $recordId = 7;

    public function recordId(): int { return $this->recordId; }
}

final class StoppedFormPage extends Page
{
    public function get(): Response { return (new Response())->status(404); }
    #[PageAction]
    public function save(): void { throw new \LogicException('Action must not run.'); }
}

final class SubmittedFormPage extends Page
{
    #[FormModel]
    public SubmittedForm $form;

    public function __construct() { $this->form = new SubmittedForm(); }

    public function template(): ?string { return "SubmittedFormPage.atom.html"; }

    public function formSaved(Form $form): ?Response
    {
        $this->flash('Form saved');
        return $this->form->notes === 'redirect' ? (new Response())->redirect('/done', 303) : null;
    }
}

final class SubmittedForm extends Form
{
    #[Required]
    public string $name = '';
    public string $notes = '';

    #[PageAction]
    public function save(): bool { return $this->validate(); }
}

final class FormResultPage extends Page
{
    #[FormModel]
    public ResultForm $form;
    #[FormModel]
    public ?ResultForm $unavailable = null;

    public function __construct() { $this->form = new ResultForm(); }

    public function template(): ?string { return 'SubmittedFormPage.atom.html'; }

    #[PageAction]
    public function coordinate(): null
    {
        $result = $this->form->save();
        if ($result === false) {
            return null;
        }
        $this->flash('Page coordinated the save');
        return null;
    }
}

final class ResultForm extends Form
{
    #[Required]
    public string $name = '';
    public string $notes = '';

    #[PageAction]
    public function save(): FormResult|false
    {
        if (!$this->validate()) {
            return false;
        }
        $location = $this->notes === 'redirect' ? '/done?q=a+b' : null;

        return FormResult::saved('Result saved', $location);
    }
}

final class ConstructorInitializedForm extends Form
{
    public string $name;
    public function __construct(string $initialName) { $this->name = $initialName; }
}
