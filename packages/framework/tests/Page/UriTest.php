<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageActionException;
use Atom\Page\PageActionHandler;
use Atom\Page\PageInputHydrator;
use Atom\Page\PageRenderer;
use Atom\Page\PageRouteMetadata;
use Atom\Page\State;
use Atom\Page\Uri;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use Atom\Router\Router;
use Atom\Session\ArraySession;
use Atom\Session\SessionInterface;
use Atom\Validation\Rules\Required;
use PHPUnit\Framework\TestCase;

final class UriTest extends TestCase
{
    public function testDefaultsAreOmittedAndOnlyDeclaredValuesAreGenerated(): void
    {
        $page = new UriTestPage();
        $route = $this->route('/catalog');
        (new PageInputHydrator())->hydrateProperties($page, new Request('GET', '/catalog', ['q' => '  a&b  ', 'tracking' => 'ignored']), $route);
        self::assertSame('a&b', $page->search);
        self::assertSame('/catalog?q=a%26b', $page->url());
        self::assertSame('/catalog?q=a%26b&section=seo', $page->url(['section' => 'seo']));
        self::assertSame('overview', $page->section);
        self::assertSame('/catalog', $page->url(['q' => null]));
    }

    public function testRouteParametersOverrideQueryAndUseTheMatchedRoutePrefix(): void
    {
        $router = new Router('/admin');
        $entry = RouteEntry::get('/catalog/{id}', static fn() => null);
        $router->add($entry);
        $route = new MatchedRoute($entry, ['id' => '7']);
        $page = new UriTestPage();
        (new PageInputHydrator())->hydrateProperties($page, new Request('GET', '/admin/catalog/7', ['id' => '999']), $route);
        self::assertSame(7, $page->id);
        self::assertSame('/admin/catalog/7', $page->url());
        self::assertSame('/admin/catalog/9?new=1', $page->url(['id' => 9, 'creating' => true]));
    }

    public function testEnhancedRouteUpdatesRenderTheNewPathParameter(): void
    {
        $route = new MatchedRoute(RouteEntry::post('/catalog/{id}', static fn() => null)
            ->metadata(new PageRouteMetadata(UriTestPage::class)), ['id' => '7']);
        $request = new Request('POST', '/catalog/7', [], ['_action' => 'save', 'name' => 'Posted'], headers: ['X-Requested-With' => 'Atom']);
        $context = $this->context($request, $route, new ArraySession());
        $injector = new Injector();
        $response = (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $route, $request);
        self::assertSame('/catalog/42?section=details', $response->headers()->get('X-Atom-Uri'));
        self::assertStringContainsString('42|Record 42|1', $response->getContent());
    }

    public function testArraysEnumsAndWildcardsRoundTripWithDefaultsOmitted(): void
    {
        $route = new MatchedRoute(RouteEntry::get('/docs/{path*}', static fn() => null), ['path' => 'guides/forms']);
        $page = new UriValuePage();
        (new PageInputHydrator())->hydrateProperties($page, new Request('GET', '/docs/guides/forms', ['tags' => ['a&b', 'c'], 'mode' => 'published']), $route);
        self::assertSame('/docs/guides/forms?tags%5B0%5D=a%26b&tags%5B1%5D=c&mode=published', $page->url());
        $this->expectException(PageActionException::class);
        $page->url(['path' => 'guides/../private']);
    }

    public function testUnknownOverridesAreRejected(): void
    {
        $page = new UriTestPage();
        $page->useUriRoute($this->route('/catalog'));
        $this->expectException(PageActionException::class);
        $page->url(['notDeclared' => 'value']);
    }

    public function testSuccessfulNormalPostRedirectsAndCarriesFlashOnce(): void
    {
        $session = new ArraySession();
        $response = $this->action(false, ['name' => 'Posted'], $session);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(303, $response->getStatus());
        $url = $response->headers()->get('Location');
        self::assertSame('/catalog?id=42&section=details', $url);
        self::assertNull($response->headers()->get('X-Atom-Uri'));
        $context = $this->context(new Request('GET', '/catalog', ['id' => '42', 'section' => 'details']), $this->route('/catalog'), $session);
        $renderer = new PageRenderer(new Injector(), $context);
        self::assertStringContainsString('Saved notice', $renderer->render(UriTestPage::class));
        self::assertStringNotContainsString('Saved notice', $renderer->render(UriTestPage::class));
    }

    public function testSuccessfulEnhancedPostReturnsFreshHtmlAndUrlWithoutRedirect(): void
    {
        $response = $this->action(true, ['name' => 'Posted'], new ArraySession());
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertNull($response->headers()->get('Location'));
        self::assertSame('/catalog?id=42&section=details', $response->headers()->get('X-Atom-Uri'));
        self::assertStringContainsString('42|Record 42|1', $response->getContent());
        self::assertStringContainsString('Saved notice', $response->getContent());
    }

    public function testFailureRetainsTheSamePageAndDoesNotPublishUrlChanges(): void
    {
        foreach ([false, true] as $enhanced) {
            $html = $this->action($enhanced, ['name' => ''], new ArraySession());
            self::assertIsString($html);
            self::assertStringContainsString('The field is required.', $html);
            self::assertStringContainsString('0||0', $html);
            self::assertStringNotContainsString('Record 42', $html);
        }
    }

    public function testExplicitResponsesOverrideTheAutomaticPolicy(): void
    {
        $response = $this->action(true, ['name' => 'Posted', '_action' => 'leave'], new ArraySession());
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('/elsewhere', $response->headers()->get('Location'));
    }

    private function route(string $path): MatchedRoute
    {
        return new MatchedRoute(RouteEntry::post($path, static fn() => null)->metadata(new PageRouteMetadata(UriTestPage::class)));
    }

    private function context(Request $request, MatchedRoute $route, ArraySession $session): InjectionContext
    {
        $context = new InjectionContext();
        $context->set(Request::class, $request);
        $context->set(MatchedRoute::class, $route);
        $context->set(SessionInterface::class, $session);
        return $context;
    }

    private function action(bool $enhanced, array $body, ArraySession $session): mixed
    {
        $request = new Request('POST', '/catalog', ['new' => '1'], ['_action' => 'save', ...$body], headers: $enhanced ? ['X-Requested-With' => 'Atom'] : []);
        $route = $this->route('/catalog');
        $context = $this->context($request, $route, $session);
        $injector = new Injector();
        return (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $route, $request);
    }
}

final class UriTestPage extends Page
{
    #[Uri('q')]
    public string $search = '';
    #[Uri]
    public int $id = 0;
    #[Uri('new')]
    public bool $creating = false;
    #[Uri]
    public string $section = 'overview';
    #[State]
    public int $counter = 0;
    #[FormModel]
    public ?UriTestForm $form = null;
    public string $loaded = '';

    public function init(): void { $this->form = new UriTestForm('Record '.$this->id); }
    public function get(): void { $this->loaded = $this->id.'|'.$this->form->name.'|'.$this->counter; }
    #[PageAction]
    public function save(): bool
    {
        if (!$this->form->validate()) return false;
        $this->id = 42;
        $this->creating = false;
        $this->section = 'details';
        ++$this->counter;
        $this->flash('Saved notice');
        return true;
    }
    #[PageAction]
    public function leave(): Response { return (new Response())->redirect('/elsewhere'); }
    public function template(): ?string { return 'UriTestPage.atom.html'; }
}

final class UriTestForm extends Form
{
    #[Required]
    public string $name;
    public function __construct(string $initialName) { $this->name = $initialName; }
}

enum UriMode: string { case Draft = 'draft'; case Published = 'published'; }

final class UriValuePage extends Page
{
    #[Uri]
    public string $path = '';
    #[Uri]
    public array $tags = [];
    #[Uri]
    public UriMode $mode = UriMode::Draft;
}
