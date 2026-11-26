<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Components\DialogModel;
use Atom\Components\DrawerModel;
use Atom\Components\SidePanelModel;
use Atom\Components\TabsModel;
use Atom\Page\Page;
use Atom\Page\PageActionHandler;
use Atom\Page\PageRenderer;
use Atom\Page\PageRouteRegistrar;
use Atom\Page\State;
use Atom\Router\RouteMatcher;
use Atom\Router\Router;
use PHPUnit\Framework\TestCase;

final class WidgetActionsTest extends TestCase
{
    public function testPageActionHandlerCanInvokeDialogModelActions(): void
    {
        $opened = $this->handlePageAction(
            DialogModelRenderedTestPage::class,
            "/dialog-model-page",
            "dialog.open(12)"
        );

        $this->assertStringContainsString("<h1>open|12</h1>\n", $opened);

        $page = new DialogModelRenderedTestPage();
        $page->dialog->open(12);
        $state = (new \Atom\Page\JsonPageStateSerializer())->serialize($page);

        $closed = $this->handlePageAction(
            DialogModelRenderedTestPage::class,
            "/dialog-model-page",
            "dialog.close()",
            ["_state" => $state]
        );

        $this->assertStringContainsString("<h1>closed|12</h1>\n", $closed);
    }

    public function testPageActionHandlerCanInvokeSidePanelModelActions(): void
    {
        $opened = $this->handlePageAction(
            SidePanelModelRenderedTestPage::class,
            "/side-panel-model-page",
            "editor.open(12)"
        );

        $this->assertStringContainsString("<h1>open|12</h1>\n", $opened);

        $page = new SidePanelModelRenderedTestPage();
        $page->editor->open(12);
        $state = (new \Atom\Page\JsonPageStateSerializer())->serialize($page);

        $closed = $this->handlePageAction(
            SidePanelModelRenderedTestPage::class,
            "/side-panel-model-page",
            "editor.close()",
            ["_state" => $state]
        );

        $this->assertStringContainsString("<h1>closed|12</h1>\n", $closed);
    }

    public function testPageActionHandlerCanInvokeDrawerModelActions(): void
    {
        $opened = $this->handlePageAction(
            DrawerModelRenderedTestPage::class,
            "/drawer-model-page",
            "drawer.open(12)"
        );

        $this->assertStringContainsString("<h1>open|12</h1>\n", $opened);

        $page = new DrawerModelRenderedTestPage();
        $page->drawer->open(12);
        $state = (new \Atom\Page\JsonPageStateSerializer())->serialize($page);

        $closed = $this->handlePageAction(
            DrawerModelRenderedTestPage::class,
            "/drawer-model-page",
            "drawer.close()",
            ["_state" => $state]
        );

        $this->assertStringContainsString("<h1>closed|12</h1>\n", $closed);
    }

    public function testPageActionHandlerCanInvokeTabsModelAction(): void
    {
        $html = $this->handlePageAction(
            TabsModelRenderedTestPage::class,
            "/tabs-model-page",
            "tabs.select('source')"
        );

        $this->assertStringContainsString("<h1>source</h1>\n", $html);
    }

    public function testPageRouteRegistrarRegistersActionsFromTypedPageModels(): void
    {
        $router = new Router();
        (new PageRouteRegistrar())->register($router, [
            new \Atom\Page\PageDescriptor("/tabs-model-page", TabsModelRenderedTestPage::class),
        ]);

        $match = (new RouteMatcher($router))->match("POST", "/tabs-model-page");

        $this->assertTrue($match->isFound());
        $this->assertSame(PageActionHandler::class, $match->matchedRoute->getRouteAction()->controllerType);
    }

    private function handlePageAction(string $pageClass, string $path, string $action, array $body = []): mixed
    {
        $router = new Router();
        (new PageRouteRegistrar())->register($router, [new \Atom\Page\PageDescriptor($path, $pageClass)]);
        $matchedRoute = (new RouteMatcher($router))->match("POST", $path)->matchedRoute;
        self::assertNotNull($matchedRoute);
        $request = new Request("POST", $path, [], [...$body, "_action" => $action]);
        $context = new InjectionContext();
        $context->set(Request::class, $request);
        $context->set(\Atom\Router\MatchedRoute::class, $matchedRoute);
        $injector = new Injector();

        return (new PageActionHandler($injector, $context))->handle(
            new PageRenderer($injector, $context),
            $matchedRoute,
            $request
        );
    }
}

final class DialogModelRenderedTestPage extends Page
{
    #[State]
    public DialogModel $dialog;

    public function __construct()
    {
        $this->dialog = new DialogModel();
    }

    public function template(): ?string
    {
        return "DialogModelRenderedTestPage.atom.html";
    }
}

final class SidePanelModelRenderedTestPage extends Page
{
    #[State]
    public SidePanelModel $editor;

    public function __construct()
    {
        $this->editor = new SidePanelModel();
    }

    public function template(): ?string
    {
        return "SidePanelModelRenderedTestPage.atom.html";
    }
}

final class DrawerModelRenderedTestPage extends Page
{
    #[State]
    public DrawerModel $drawer;

    public function __construct()
    {
        $this->drawer = new DrawerModel();
    }

    public function template(): ?string
    {
        return "DrawerModelRenderedTestPage.atom.html";
    }
}

final class TabsModelRenderedTestPage extends Page
{
    #[State]
    public TabsModel $tabs;

    public function __construct()
    {
        $this->tabs = new TabsModel("preview");
    }

    public function template(): ?string
    {
        return "TabsModelRenderedTestPage.atom.html";
    }
}
