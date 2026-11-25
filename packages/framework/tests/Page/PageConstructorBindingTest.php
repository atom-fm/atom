<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Hydrator\Attributes\FromQuery;
use Atom\Hydrator\Attributes\FromRoute;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageActionException;
use Atom\Page\PageActionHandler;
use Atom\Page\PageRenderer;
use Atom\Page\PageRouteMetadata;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use PHPUnit\Framework\TestCase;

final class PageConstructorBindingTest extends TestCase
{
    public function testGetBindsBeforeConstructionAndStillInjectsServices(): void
    {
        $request = new Request('GET', '/catalog', ['id' => '7', 'variant' => ' new ', 'new' => '1', 'service' => 'forged']);
        [$context, $route] = $this->context($request);
        $html = (new PageRenderer(new Injector(), $context))->render(ConstructorBoundPage::class);
        self::assertSame('<p>7|new|1|5|injected:get</p>', trim($html));
    }

    public function testMissingQueryValuesUseConstructorDefaults(): void
    {
        [$context] = $this->context(new Request('GET', '/catalog'));
        $html = (new PageRenderer(new Injector(), $context))->render(ConstructorBoundPage::class);
        self::assertSame('<p>0||0|5|injected:get</p>', trim($html));
    }

    public function testPostUsesQueryValuesInsteadOfBodyFieldsForConstructorContext(): void
    {
        $request = new Request('POST', '/catalog', ['id' => '7', 'variant' => 'new', 'new' => '0'], parsedBody: [
            '_action' => 'save', 'id' => '999', 'variant' => '55', 'new' => '1',
        ]);
        [$context, $route] = $this->context($request);
        $injector = new Injector();
        $response = (new PageActionHandler($injector, $context))->handle(new PageRenderer($injector, $context), $route, $request);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('7|new|0|5|injected:get', $response->getContent());
    }

    public function testInvalidConstructorQueryValueReportsItsParameter(): void
    {
        [$context] = $this->context(new Request('GET', '/catalog', ['id' => 'not-an-integer']));
        $this->expectException(PageActionException::class);
        $this->expectExceptionMessage("constructor parameter 'id'");
        (new PageRenderer(new Injector(), $context))->render(ConstructorBoundPage::class);
    }

    private function context(Request $request): array
    {
        $route = new MatchedRoute(RouteEntry::get('/catalog', static fn() => null)
            ->metadata(new PageRouteMetadata(ConstructorBoundPage::class)), ['store' => '5']);
        $context = new InjectionContext();
        $context->set(Request::class, $request);
        $context->set(MatchedRoute::class, $route);
        $context->set(ConstructorPageService::class, new ConstructorPageService());
        return [$context, $route];
    }
}

final class ConstructorBoundPage extends Page
{
    public string $loaded;

    public function __construct(
        ConstructorPageService $service,
        #[FromQuery] int $id = 0,
        #[FromQuery] string $variant = '',
        #[FromQuery('new')] bool $creating = false,
        #[FromRoute] int $store = 1,
    ) {
        $this->loaded = $id.'|'.$variant.'|'.(int) $creating.'|'.$store.'|'.$service->value;
    }

    public function get(): void { $this->loaded .= ':get'; }
    public function template(): ?string { return 'ConstructorBoundPage.atom.html'; }
    #[PageAction]
    public function save(): Response { return (new Response())->content($this->loaded); }
}

final class ConstructorPageService
{
    public string $value = 'injected';
}
