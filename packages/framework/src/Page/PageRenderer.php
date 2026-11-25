<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Profiler\Profile;
use Atom\Router\MatchedRoute;
use Atom\View\Ast\AttributeNode;
use Atom\View\Ast\ElementNode;
use Atom\View\Ast\ExpressionNode;
use Atom\View\Ast\RawTextNode;
use Atom\View\Ast\TemplateNode;
use Atom\View\Html;
use Atom\View\TemplateCache;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;
use ReflectionClass;

final readonly class PageRenderer
{
    public function __construct(
        private Injector $injector,
        private InjectionContext $context,
        private PageViewLocator $viewLocator = new PageViewLocator(),
        private ViewParser $parser = new ViewParser(),
        private TemplateCache $templates = new TemplateCache(),
        private ViewRenderer $renderer = new ViewRenderer(),
        private PageStateSerializerInterface $state = new JsonPageStateSerializer(),
        private PageInputHydratorInterface $input = new PageInputHydrator(),
        private PageConstructorParameterBinder $constructorParameters = new PageConstructorParameterBinder()
    ) {
    }

    /**
     * @param class-string<Page> $pageClass
     */
    public function render(string $pageClass): mixed
    {
        return Profile::measure("page.render", function () use ($pageClass): mixed {
            $request = $this->context->get(Request::class);
            $route = $this->context->get(MatchedRoute::class);
            $parameters = $request instanceof Request
                ? $this->constructorParameters->bind($pageClass, $request, $route instanceof MatchedRoute ? $route : null)
                : [];
            $page = $this->injector->instantiate($pageClass, $parameters, $this->context);
            $this->restoreState($page);

            return $this->renderPage($page);
        }, ["page" => $pageClass]);
    }

    public function renderPage(Page $page, bool $invokeGet = true): mixed
    {
        if ($invokeGet) {
            $request = $this->context->get(Request::class);
            $route = $this->context->get(MatchedRoute::class);
            if ($request instanceof Request && $route instanceof MatchedRoute) {
                $this->input->hydrateProperties($page, $request, $route);
            }
            $page->init();
            if ($request instanceof Request && $route instanceof MatchedRoute) {
                $this->input->hydrateForms($page, $request, $route);
            }
        }

        $result = $invokeGet
            ? Profile::measure("page.invoke.get", fn(): mixed => $this->invokeGet($page), ["page" => $page::class])
            : null;

        if ($result !== null) {
            return $result;
        }

        $hasUriBindings = (new PageUri())->hasBindings($page);
        $request = $this->context->get(Request::class);
        $requestUri = $request instanceof Request && $request->getMethod() === "GET" ? $request->getRequestUri() : null;
        if ($hasUriBindings || $requestUri !== null) {
            (new PageFlashStore($this->injector, $this->context))->restore($page, $hasUriBindings ? $page->url() : null, $requestUri);
        }

        $variables = [
            "this" => $page,
            "page" => $page,
            "currentPath" => $this->currentPath(),
        ];
        $renderer = $this->activeRenderer();

        $templatePath = $this->viewLocator->locate($page);
        $template = $this->templates->remember(
            $templatePath,
            fn(): TemplateNode => Profile::measure(
                "view.parse",
                fn(): TemplateNode => $this->parser->parse(file_get_contents($templatePath) ?: ""),
                ["page" => $page::class, "template" => $templatePath]
            )
        );
        $content = Profile::measure(
            "view.render",
            fn(): string => $renderer->render($template, $variables),
            ["page" => $page::class, "template" => $templatePath]
        );

        $html = Profile::measure(
            "page.layout",
            fn(): string => $this->renderLayout($renderer, $page, $content, $variables),
            ["page" => $page::class]
        );
        $state = Profile::measure("page.state", fn(): string => $this->state->serialize($page), ["page" => $page::class]);

        return $this->injectState($html, $state);
    }

    /** Render a fresh GET snapshot internally after a successful URI-state action. */
    public function renderUpdated(Page $page, string $url, MatchedRoute $route, Request $request): mixed
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $router = new \Atom\Router\Router();
        $router->add(\Atom\Router\RouteEntry::get($route->getRouteEntry()->getFullPath(), static fn() => null));
        $match = (new \Atom\Router\RouteMatcher($router))->match("GET", $path);
        if (!$match->isFound()) throw new PageActionException("Updated URI does not match the Page route.");
        $updatedRoute = new MatchedRoute($route->getRouteEntry(), $match->matchedRoute->getRouteParams());
        $context = clone $this->context;
        $updatedRequest = new Request("GET", $path, $query, serverParams: $request->server()->toArray(), headers: $request->headers()->toArray());
        $context->set(Request::class, $updatedRequest);
        $context->set(MatchedRoute::class, $updatedRoute);
        $next = $this->injector->instantiate($page::class, $this->constructorParameters->bind($page::class, $updatedRequest, $updatedRoute), $context);
        $this->state->deserialize($next, $this->state->serialize($page));
        if ($page->hasFlash()) $next->flash($page->flashMessage(), $page->flashVariant(), $page->flashTitle());
        $renderer = new self($this->injector, $context, $this->viewLocator, $this->parser, $this->templates, $this->renderer, $this->state, $this->input, $this->constructorParameters);
        return $renderer->renderPage($next);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderLayout(
        ViewRenderer $renderer,
        Page $page,
        string $content,
        array $variables
    ): string
    {
        $layout = $page->layout;
        if ($layout === null) {
            return $content;
        }

        return $renderer->render(new TemplateNode([
            new ElementNode($layout, [
                new AttributeNode("page", new ExpressionNode('$page')),
            ], [
                new RawTextNode($content),
            ]),
        ]), $variables);
    }

    private function invokeGet(Page $page): mixed
    {
        $reflection = new ReflectionClass($page);
        if (!$reflection->hasMethod("get")) {
            return null;
        }

        return $this->injector->invoke([$page, "get"], $this->routeParams(), $this->context);
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParams(): array
    {
        $route = $this->context->get(MatchedRoute::class);

        return $route instanceof MatchedRoute ? $route->getRouteParams() : [];
    }

    private function currentPath(): string
    {
        $request = $this->context->get(Request::class);

        return $request instanceof Request ? $request->getPath() : "";
    }

    private function restoreState(Page $page): void
    {
        $request = $this->context->get(Request::class);
        if (!$request instanceof Request) {
            return;
        }

        $state = $request->post()->string("_state", $request->query()->string("_state"));
        $this->state->deserialize($page, $state);
    }

    private function injectState(string $html, string $state): string
    {
        if ($state === "") {
            return $html;
        }

        $meta = '<meta name="atom-state" content="' . Html::escape($state) . '">';

        if (str_contains($html, 'name="atom-state"')) {
            return preg_replace('/<meta\s+name="atom-state"\s+content="[^"]*"\s*\/?>/i', $meta, $html, 1) ?? $html;
        }

        if (stripos($html, "</head>") !== false) {
            return preg_replace('/<\/head>/i', "    {$meta}\n</head>", $html, 1) ?? $html;
        }

        return $meta . $html;
    }

    private function activeRenderer(): ViewRenderer
    {
        $route = $this->context->get(MatchedRoute::class);
        if (!$route instanceof MatchedRoute) {
            return $this->renderer;
        }

        $scope = $route->moduleScope();

        return $scope === null
            ? $this->renderer
            : $this->renderer->withComponents($scope->components);
    }
}
