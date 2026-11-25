<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Hydrator\Attributes\FromQuery;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\JsonPageStateSerializer;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageActionHandler;
use Atom\Page\PageRenderer;
use Atom\Page\PageRouteMetadata;
use Atom\Page\State;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use Atom\Validation\Rules\Required;
use PHPUnit\Framework\TestCase;

final class PageInitializationTest extends TestCase
{
    public function testGetBindsPropertiesBeforeInitCreatesTheForm(): void
    {
        [$context] = $this->context(new Request('GET', '/init', ['id' => '7']));
        $html = (new PageRenderer(new Injector(), $context))->render(InitializedTestPage::class);
        self::assertStringContainsString('constructor;init:7:0;get:Default 7', $html);
        self::assertStringContainsString('<p>Default 7</p>', $html);
        self::assertSame(1, substr_count($html, 'init:'));
    }

    public function testPostRestoresStateThenInitializesThenHydratesThenInvokesTheAction(): void
    {
        $state = new InitializedTestPage();
        $state->counter = 9;
        $request = new Request('POST', '/init', ['id' => '7'], [
            '_state' => (new JsonPageStateSerializer())->serialize($state),
            '_action' => 'save', 'name' => ' Posted value ', 'id' => '999',
        ]);
        [$context, $route] = $this->context($request);
        $injector = new Injector();
        $html = (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $route, $request);
        self::assertStringContainsString('constructor;init:7:9;get:Posted value;action', $html);
        self::assertStringContainsString('<p>Posted value</p>', $html);
        self::assertSame(1, substr_count($html, 'init:'));
    }

    public function testFailedActionRendersErrorsWithoutInitializingAgain(): void
    {
        $request = new Request('POST', '/init', ['id' => '7'], ['_action' => 'save', 'name' => '']);
        [$context, $route] = $this->context($request);
        $injector = new Injector();
        $html = (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $route, $request);
        self::assertStringContainsString('constructor;init:7:0;get:;action', $html);
        self::assertStringContainsString('The field is required.', $html);
        self::assertStringNotContainsString('Default 7', $html);
        self::assertSame(1, substr_count($html, 'init:'));
    }

    public function testRenderingAnExistingPageAlsoRunsInitialization(): void
    {
        [$context] = $this->context(new Request('GET', '/init', ['id' => '3']));
        $html = (new PageRenderer(new Injector(), $context))->renderPage(new InitializedTestPage());
        self::assertStringContainsString('constructor;init:3:0;get:Default 3', $html);
    }

    private function context(Request $request): array
    {
        $route = new MatchedRoute(RouteEntry::get('/init', static fn() => null)
            ->metadata(new PageRouteMetadata(InitializedTestPage::class)));
        $context = new InjectionContext();
        $context->set(Request::class, $request);
        $context->set(MatchedRoute::class, $route);
        return [$context, $route];
    }
}

final class InitializedTestPage extends Page
{
    public array $events = [];
    #[FromQuery]
    public int $id = 0;
    #[State]
    public int $counter = 0;
    #[FormModel]
    public ?InitializedTestForm $form = null;

    public function __construct() { $this->events[] = 'constructor'; }
    public function init(): void
    {
        $this->events[] = 'init:'.$this->id.':'.$this->counter;
        $this->form = new InitializedTestForm('Default '.$this->id);
    }
    public function get(): void { $this->events[] = 'get:'.$this->form->name; }
    #[PageAction]
    public function save(): void
    {
        $this->events[] = 'action';
        $this->form->validate();
    }
    public function template(): ?string { return 'InitializedTestPage.atom.html'; }
}

final class InitializedTestForm extends Form
{
    #[Required]
    public string $name;
    public function __construct(string $initialName) { $this->name = $initialName; }
}
