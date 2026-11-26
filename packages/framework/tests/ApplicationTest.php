<?php

declare(strict_types=1);

namespace Atom\Tests;

use Atom\Application;
use Atom\BootstrapperInterface;
use Atom\BootstrapProviderInterface;
use Atom\Bootstrappers;
use Atom\Config\Config;
use Atom\Config\Options;
use Atom\Console\BufferedConsoleOutput;
use Atom\Console\ConsoleApplication;
use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;
use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderInterface;
use Atom\Di\ServiceProviderRegistry;
use Atom\Dispatcher\ResponseEmitterInterface;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Http\Cookie;
use Atom\Http\CorsMiddleware;
use Atom\Http\CorsOptions;
use Atom\Http\MiddlewareInterface;
use Atom\Http\MiddlewareRegistry;
use Atom\Http\RequestHandlerInterface;
use Atom\Http\RequestIdMiddleware;
use Atom\Module\ModuleContext;
use Atom\Module\ModuleInterface;
use Atom\Module\ModuleRegistry;
use Atom\Page\PageRegistry;
use Atom\View\Icons\IconProviderInterface;
use Atom\View\Icons\IconRegistry;
use Atom\View\Icons\IconResolverInterface;
use Atom\Modules\ErrorPages\ErrorPagesOptions;
use Atom\Profiler\Profile;
use Atom\Profiler\Profiler;
use Atom\Router\Route;
use Atom\Router\RouteEntry;
use Atom\Router\RouteMatcher;
use Atom\Router\Router;
use Atom\Support\Paths;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\ComponentRegistry;
use Atom\View\TemplateCache;
use Atom\View\Templates;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv("ATOM_APP_TEST_NAME");
        unset($_ENV["ATOM_APP_TEST_NAME"], $_SERVER["ATOM_APP_TEST_NAME"]);

        Application::$app = null;
        Route::clearRouter();
    }

    public function testInitializeRegistersServicesAndBootstrapsRouteFacade(): void
    {
        $app = new TestApplication();

        $app->initialize();

        $this->assertTrue($app->bootstrapped);
        $this->assertSame("ready", $app->getInjector()->get(TestApplicationMarker::class)->value);
        $this->assertTrue($app->getInjector()->get(IconRegistry::class)->has("test"));

        $match = (new RouteMatcher($app->getRouter()))->match("GET", "/hello/Atom");

        $this->assertTrue($match->isFound());
        $this->assertSame(["name" => "Atom"], $match->matchedRoute->getRouteParams());
    }

    #[TestWith([false, "module"], "module overrides an unresolved binding")]
    #[TestWith([true, "module"], "module replaces a resolved singleton")]
    public function testModuleBindingPrecedence(bool $resolveEarly, string $expected): void
    {
        $app = new class($resolveEarly) extends Application {
            public ?TestApplicationMarker $original = null;

            public function __construct(private bool $resolveEarly)
            {
                parent::__construct();
            }

            protected function services(ServiceProviderRegistry $providers): void
            {
                $providers->add(new TestApplicationProvider(new TestResponseEmitter()));
            }

            protected function modules(ModuleRegistry $modules): void
            {
                if ($this->resolveEarly) {
                    $this->original = $this->getInjector()->get(TestApplicationMarker::class);
                }
                $modules->add(new class implements ModuleInterface {
                    public function register(ModuleContext $context): void
                    {
                        $context->bind(TestApplicationMarker::class)->toValue(new TestApplicationMarker("module"));
                        $context->route(RouteEntry::create(
                            "GET",
                            "/binding",
                            static fn(TestApplicationMarker $marker): string => $marker->value
                        ));
                    }
                });
            }
        };

        $response = $app->handle(new Request("GET", "/binding"));
        $this->assertSame($expected, $response->getContent());
        if ($resolveEarly) {
            $this->assertNotSame($app->original, $app->getInjector()->get(TestApplicationMarker::class));
        }
    }

    public function testEarlyConsoleCanRunCommandsRegisteredByAModule(): void
    {
        $app = new class extends Application {
            public ConsoleApplication $earlyConsole;
            public bool $discoveredEarly;

            protected function modules(ModuleRegistry $modules): void
            {
                $this->earlyConsole = $this->getConsole();
                $this->discoveredEarly = $this->earlyConsole->commands()->has("module:hello");
                $modules->add(new class implements ModuleInterface {
                    public function register(ModuleContext $context): void
                    {
                        $context->commands(__DIR__ . "/Module/Fixtures/Commands", "Atom\\Tests\\Module\\Fixtures\\Commands");
                    }
                });
            }
        };

        $app->initialize();

        $this->assertFalse($app->discoveredEarly);
        $this->assertSame($app->earlyConsole, $app->getConsole());
        $output = new BufferedConsoleOutput();
        $this->assertSame(0, $app->earlyConsole->run(["atom", "module:hello", "Atom"], $output), $output->errors());
        $this->assertSame("Hello Atom from module" . PHP_EOL, $output->output());
        $this->assertTrue($app->earlyConsole->commands()->has("atom:about"));
    }

    public function testHttpRejectsSingletonThatCapturesTheCurrentRequest(): void
    {
        $app = new class extends Application {
            protected function configure(Config $config): void
            {
                $config->set(new ErrorPagesOptions(debug: true));
            }

            protected function bootstrap(Injector $injector): void
            {
                $this->getBindings()->bind(HttpRequestSingleton::class)->toSelf()->singleton();
                Route::get("/capture", static fn(HttpRequestSingleton $service): string => "handler ran");
            }
        };

        $response = $app->handle(new Request("GET", "/capture"));
        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString("Singleton '" . HttpRequestSingleton::class, html_entity_decode($response->getContent()));
        $this->assertStringContainsString("scoped service '" . Request::class, html_entity_decode($response->getContent()));
    }

    public function testConsoleRejectsSingletonThatIndirectlyCapturesCommandOutput(): void
    {
        $app = new class extends Application {
            protected function bootstrap(Injector $injector): void
            {
                $this->getBindings()->bind(CommandOutputSingleton::class)
                    ->toFactory(static fn(Injector $injector): CommandOutputSingleton =>
                        new CommandOutputSingleton($injector->get(CommandOutputHelper::class))
                    )->singleton();
                $this->getConsole()->attach(CommandOutputProbe::class);
            }
        };
        $app->initialize();

        $output = new BufferedConsoleOutput();
        $this->assertSame(1, $app->getConsole()->run(["atom", "test:capture-output"], $output));
        $this->assertStringContainsString("Singleton '" . CommandOutputSingleton::class, $output->errors());
        $this->assertStringContainsString("scoped service '" . ConsoleOutput::class, $output->errors());
        $this->assertStringContainsString(CommandOutputHelper::class, $output->errors());
        $this->assertSame("", $output->output());
    }

    public function testRunUsesRequestContextAndReturnsResponse(): void
    {
        $app = new TestApplication();

        $response = $app->run(new Request("GET", "/hello/Atom"));

        $this->assertSame("Hello Atom", $response->getContent());
        $this->assertSame($response, $app->emitter->response);
        $this->assertSame(["name" => "Atom"], $app->getCurrentRoute()?->getRouteParams());
    }

    public function testHandleReturnsResponseWithoutEmitting(): void
    {
        $app = new TestApplication();

        $response = $app->handle(new Request("GET", "/hello/Atom"));

        $this->assertSame("Hello Atom", $response->getContent());
        $this->assertNull($app->emitter->response);
        $this->assertSame(["name" => "Atom"], $app->getCurrentRoute()?->getRouteParams());
    }

    public function testFailedRequestReleasesItsScopeBeforeSuccessfulRun(): void
    {
        $app = new TestApplication();
        $app->initialize();
        $emittedRequest = null;
        $app->getBindings()->factory(
            ResponseEmitterInterface::class,
            static function (Injector $injector, InjectionContext $context) use ($app, &$emittedRequest): ResponseEmitterInterface {
                $emittedRequest = $injector->get(Request::class, $context);
                return $app->emitter;
            }
        )->scoped();

        $failedRequest = new Request("GET", "/fail/first");
        $failed = $app->handle($failedRequest);
        self::assertSame(500, $failed->getStatus());
        self::assertSame(["name" => "first"], $app->getCurrentRoute()?->getRouteParams());
        self::assertNotEmpty($failed->headers()->all("Set-Cookie"));
        self::assertNotNull($failed->headers()->get("X-Atom-Time-Failed-Work"));
        self::assertNull($app->emitter->response);
        self::assertNotSame($failedRequest, $app->getRequest());

        $successfulRequest = new Request("GET", "/hello/second");
        $successful = $app->run($successfulRequest);
        self::assertSame(200, $successful->getStatus());
        self::assertSame("Hello second", $successful->getContent());
        self::assertSame(["name" => "second"], $app->getCurrentRoute()?->getRouteParams());
        self::assertSame([], $successful->headers()->all("Set-Cookie"));
        self::assertNull($successful->headers()->get("X-Atom-Time-Failed-Work"));
        self::assertNotNull($successful->headers()->get("X-Atom-Time-Request-Dispatch"));
        self::assertSame($successful, $app->emitter->response);
        self::assertSame($successfulRequest, $emittedRequest);
        self::assertNotSame($successfulRequest, $app->getRequest());
    }

    public function testNestedRequestRestoresTheOuterRequestScope(): void
    {
        $app = new TestApplication();
        $response = $app->handle(new Request("GET", "/nested/outer"));

        self::assertSame(200, $response->getStatus());
        self::assertSame("500 /nested/outer outer", $response->getContent());
        self::assertSame(["name" => "outer"], $app->getCurrentRoute()?->getRouteParams());
        self::assertSame([], $response->headers()->all("Set-Cookie"));
        self::assertNull($response->headers()->get("X-Atom-Time-Failed-Work"));
    }

    public function testGlobalCorsMiddlewareHandlesPreflightBeforeRouteMatching(): void
    {
        $app = new TestCorsApplication();

        $response = $app->handle(new Request("OPTIONS", "/api", headers: [
            "Origin" => "https://app.example.com",
            "Access-Control-Request-Method" => "GET",
        ]));

        $this->assertSame(204, $response->getStatus());
        $this->assertSame("https://app.example.com", $response->headers()->get("Access-Control-Allow-Origin"));
        $this->assertContains(CorsMiddleware::class, $app->getMiddlewares()->all());
    }

    public function testGlobalResponseMiddlewareProcessesRenderedExceptions(): void
    {
        $app = new TestCorsApplication();

        $response = $app->handle(new Request("GET", "/explode"));

        $this->assertSame(500, $response->getStatus());
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/',
            $response->headers()->get("X-Request-Id", "") ?? ""
        );
    }

    public function testMiddlewareWrapsRouteActionInRegistrationOrder(): void
    {
        $middleware = static fn(string $name): MiddlewareInterface => new class ($name) implements MiddlewareInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function process(Request $request, RequestHandlerInterface $handler): Response
            {
                $order = $request->headers()->get("X-Middleware-Order") ?? "";
                $response = $handler->handle($request->withHeader("X-Middleware-Order", $order . $this->name . ":"));

                return $response->content($response->getContent() . ":" . $this->name);
            }
        };

        $app = new TestApplication();
        $app->initialize();
        $app->getMiddlewares()->add($middleware("global"));
        $router = $app->getRouter()->middleware($middleware("router"));
        $group = (new Router("/group"))->middleware($middleware("group"));
        $router->add($group);
        $group->add(RouteEntry::get(
            "/action",
            static fn(Request $request): string => $request->headers()->get("X-Middleware-Order") . "action"
        )->middleware($middleware("route")));

        $response = $app->handle(new Request("GET", "/group/action"));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame("global:router:group:route:action:route:group:router:global", $response->getContent());
    }

    public function testQueuedCookieIsAppliedWhenActionReturnsDifferentResponse(): void
    {
        $app = new TestApplication();

        $response = $app->handle(new Request("GET", "/cookie-on-other-response"));

        $this->assertSame("final", $response->getContent());
        $this->assertSame(
            ["theme=dark; Path=/; HttpOnly; SameSite=Lax"],
            $response->headers()->all("Set-Cookie")
        );
    }

    public function testTemplatesShareTheApplicationCacheWhenAvailable(): void
    {
        $fallback = Templates::cache();
        $app = new TestApplication();
        $this->assertSame($fallback, Templates::cache());

        $app->initialize();
        $cache = $app->getInjector()->get(TemplateCache::class);

        $this->assertNotSame($fallback, $cache);
        $this->assertSame($cache, Templates::cache());

        Application::$app = null;
        $this->assertSame($fallback, Templates::cache());
    }

    public function testRunAddsProfilerHeadersAndBindsProfiler(): void
    {
        $app = new TestApplication();

        $response = $app->handle(new Request("GET", "/hello/Atom"));

        $this->assertSame($app->getProfiler(), $app->getInjector()->get(Profiler::class));
        $this->assertSame($app->getProfiler(), Profile::profiler());
        $this->assertSame(1, $app->getProfiler()->count("test.bootstrap"));
        $this->assertNotNull($response->headers()->get("X-Atom-Time"));
        $this->assertNotNull($response->headers()->get("X-Atom-Time-App-Initialize"));
        $this->assertNotNull($response->headers()->get("X-Atom-Time-Request-Dispatch"));
    }

    public function testProfilingSeparatesRequestsOnTheSameApplication(): void
    {
        $app = new TestApplication();
        $first = $app->handle(new Request("GET", "/profile/repeated"));
        $second = $app->handle(new Request("GET", "/hello/Atom"));

        $this->assertStringContainsString("count=2", (string) $first->headers()->get("X-Atom-Time-Custom-Work"));
        $this->assertNotNull($first->headers()->get("X-Atom-Time-App-Initialize"));
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}ms$/', (string) $second->headers()->get("X-Atom-Time-Request-Dispatch"));
        $this->assertNull($second->headers()->get("X-Atom-Time-Custom-Work"));
        $this->assertNull($second->headers()->get("X-Atom-Time-App-Initialize"));
        $this->assertSame(1, $app->getProfiler()->count("app.initialize"));
        $this->assertSame(0, $app->getProfiler()->count("request.dispatch"));
    }

    public function testPageResponsesIncludeViewProfilerHeaders(): void
    {
        $app = new TestPageApplication(__DIR__ . "/Page/PageFixtures");

        $response = $app->handle(new Request("GET", "/app/hello-page"));

        $this->assertNotNull($response->headers()->get("X-Atom-Time-Page-Render"));
        $this->assertNotNull($response->headers()->get("X-Atom-Time-View-Parse"));
        $this->assertNotNull($response->headers()->get("X-Atom-Time-View-Render"));
    }

    public function testProfilerHeadersSummarizeRepeatedSpans(): void
    {
        $app = new TestApplication();

        $response = $app->handle(new Request("GET", "/profile/repeated"));

        $this->assertStringContainsString("count=2", (string) $response->headers()->get("X-Atom-Time-Custom-Work"));
    }

    public function testProfilerHeadersPreserveCollidingSpanSummaries(): void
    {
        $response = (new TestApplication())->handle(new Request("GET", "/profile/collisions"));
        $headers = $response->headers();

        self::assertStringContainsString("count=2", (string) $headers->get("X-Atom-Time-Cache-Read"));
        self::assertStringContainsString("count=3", (string) $headers->get("X-Atom-Time-Cache-Read-2"));
        self::assertMatchesRegularExpression('/^\d+\.\d{2}ms$/', (string) $headers->get("X-Atom-Time-Cache-Read-3"));
        self::assertMatchesRegularExpression('/^\d+\.\d{2}ms$/', (string) $headers->get("X-Atom-Time-App-Initialize"));
        self::assertStringContainsString("count=2", (string) $headers->get("X-Atom-Time-App-Initialize-2"));
    }

    public function testApplicationProvidesConsoleWithFrameworkCommands(): void
    {
        $app = new TestApplication();
        $app->initialize();

        $output = new \Atom\Console\BufferedConsoleOutput();
        $code = $app->getConsole()->run(["atom", "atom:about"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("Atom Framework" . PHP_EOL, $output->output());
    }

    public function testEnvironmentFilesLoadBeforeConfigAndServices(): void
    {
        $path = tempnam(sys_get_temp_dir(), "atom_app_env_");
        $this->assertIsString($path);
        file_put_contents($path, "ATOM_APP_TEST_NAME=Atom\n");

        $app = new TestEnvironmentApplication($path);
        $app->initialize();

        $this->assertSame("Atom", $app->configuredName);
        $this->assertSame("Atom", $app->serviceName);
    }

    public function testOptionsClassesCanBeInjectedDirectly(): void
    {
        $app = new TestApplication();
        $app->initialize();
        $app->getConfig()->set(new TestEnvironmentOptions("Injected"));

        $service = $app->getInjector()->get(TestOptionsConsumer::class);

        $this->assertSame("Injected", $service->options->name);
    }

    public function testFrameworkPathAliasesAreRegisteredByDefault(): void
    {
        $app = new TestApplication();
        $app->initialize();

        $root = str_replace("\\", "/", __DIR__);

        $this->assertSame($root, $app->getPaths()->resolve("@root"));
        $this->assertSame($root . "/app", $app->getPaths()->resolve("@app"));
        $this->assertSame($root . "/public", $app->getPaths()->resolve("@public"));
        $this->assertSame($root . "/app/Database", $app->getPaths()->resolve("@database"));
        $this->assertSame($root . "/app/Database/Migrations", $app->getPaths()->resolve("@migrations"));
        $this->assertSame($root . "/app/Database/Seeders", $app->getPaths()->resolve("@seeders"));
    }

    public function testPathsAreConfiguredBeforeEnvironmentFiles(): void
    {
        $directory = sys_get_temp_dir() . "/atom_paths_" . uniqid();
        mkdir($directory);
        file_put_contents($directory . "/.env", "ATOM_APP_TEST_NAME=Alias\n");

        $app = new TestPathApplication($directory);
        $app->initialize();

        $this->assertSame("Alias", $app->configuredName);
        $this->assertSame($app->getPaths(), $app->getInjector()->get(Paths::class));
        $this->assertSame(str_replace("\\", "/", $directory . "/app"), $app->getPaths()->resolve("@app"));
    }

    public function testDefaultEnvironmentFileLoadsFromRootPath(): void
    {
        $directory = sys_get_temp_dir() . "/atom_default_env_" . uniqid();
        mkdir($directory);
        file_put_contents($directory . "/.env", "ATOM_APP_TEST_NAME=DefaultEnv\n");

        $app = new TestPathApplication($directory);
        $app->initialize();

        $this->assertSame("DefaultEnv", $app->configuredName);
    }

    public function testPagesAreRegisteredFromApplicationHookBeforeBootstrap(): void
    {
        $app = new TestPageApplication(__DIR__ . "/Page/PageFixtures");
        $app->initialize();

        $match = (new RouteMatcher($app->getRouter()))->match("GET", "/app/hello-page");

        $this->assertTrue($match->isFound());
        $this->assertTrue($app->pageRouteWasAvailableInBootstrap);
        $this->assertCount(1, $app->getPages()->directories());
    }

    public function testComponentsAreRegisteredFromApplicationHookBeforeBootstrap(): void
    {
        $app = new TestComponentApplication();
        $app->initialize();

        $components = $app->getInjector()->get(ComponentRegistry::class);

        $this->assertSame(TestApplicationComponent::class, $components->get("App.Test"));
        $this->assertTrue($app->componentWasAvailableInBootstrap);
    }

    public function testServiceProviderBootstrappersRunBeforeApplicationBootstrap(): void
    {
        $app = new TestBootstrapperApplication();
        $app->initialize();

        $this->assertSame(["provider", "app"], $app->events);
        $this->assertCount(1, $app->getBootstrappers()->all());
    }
}

final class TestApplication extends Application
{
    public bool $bootstrapped = false;
    public TestResponseEmitter $emitter;

    protected function services(ServiceProviderRegistry $providers): void
    {
        $this->emitter = new TestResponseEmitter();
        $providers->add(new TestApplicationProvider($this->emitter));
    }

    protected function bootstrap(Injector $injector): void
    {
        $this->bootstrapped = true;
        Profile::measure("test.bootstrap", static fn(): null => null);

        Route::get("/hello/{name}", fn(string $name) => "Hello " . $name);
        Route::get("/fail/{name}", static function (Response $response): never {
            $response->cookie(Cookie::create("failed", "request"));
            Profile::measure("failed.work", static fn(): null => null);
            throw new \RuntimeException("Request failed.");
        });
        Route::get("/nested/{name}", function (): string {
            $inner = $this->handle(new Request("GET", "/fail/inner"));
            $name = $this->getCurrentRoute()?->getRouteParams()["name"] ?? "";
            return $inner->getStatus() . " " . $this->getRequest()->getPath() . " " . $name;
        });
        Route::get("/profile/repeated", function (Profiler $profiler): string {
            $profiler->measure("custom.work", static fn(): null => null);
            Profile::measure("custom.work", static fn(): null => null);

            return "ok";
        });
        Route::get("/profile/collisions", static function (Profiler $profiler): string {
            foreach (["cache.read", "cache.read", "cache.read-2", "cache.read-2", "cache.read-2", "cache-read", "app.initialize", "app.initialize"] as $name) {
                $profiler->begin($name)->end();
            }

            return "ok";
        });
        Route::get("/cookie-on-other-response", function (Response $response): Response {
            $response->cookie(Cookie::create("theme", "dark"));
            return (new Response())->content("final");
        });
    }
}

final readonly class TestApplicationProvider implements ServiceProviderInterface, IconProviderInterface
{
    public function __construct(private TestResponseEmitter $emitter)
    {
    }

    public function register(Bindings $bindings): void
    {
        $bindings->bind(TestApplicationMarker::class)
            ->toFactory(fn() => new TestApplicationMarker("ready"))
            ->singleton();

        $bindings->bind(ResponseEmitterInterface::class)
            ->toValue($this->emitter);
    }

    public function icons(IconRegistry $icons): void
    {
        $icons->register("test", new class implements IconResolverInterface {
            public function resolve(string $name): string
            {
                return "test:{$name}";
            }
        });
    }
}

final class TestBootstrapperApplication extends Application
{
    /** @var list<string> */
    public array $events = [];

    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers->add(new TestBootstrapperProvider($this));
    }

    protected function bootstrap(Injector $injector): void
    {
        $this->events[] = "app";
    }
}

final readonly class TestBootstrapperProvider implements ServiceProviderInterface, BootstrapProviderInterface
{
    public function __construct(private TestBootstrapperApplication $app)
    {
    }

    public function register(Bindings $bindings): void
    {
    }

    public function bootstrappers(Bootstrappers $bootstrappers): void
    {
        $bootstrappers->add(new TestBootstrapper($this->app));
    }
}

final readonly class TestBootstrapper implements BootstrapperInterface
{
    public function __construct(private TestBootstrapperApplication $app)
    {
    }

    public function bootstrap(Injector $injector): void
    {
        $this->app->events[] = "provider";
    }
}

final readonly class HttpRequestSingleton
{
    public function __construct(public ?Request $request)
    {
    }
}

final readonly class CommandOutputSingleton
{
    public function __construct(public CommandOutputHelper $helper)
    {
    }
}

final readonly class CommandOutputHelper
{
    public function __construct(public ConsoleOutput $output)
    {
    }
}

final class CommandOutputProbe
{
    #[ConsoleCommand("test:capture-output")]
    public function handle(CommandOutputSingleton $service): string
    {
        return "handler ran";
    }
}

final readonly class TestApplicationMarker
{
    public function __construct(public string $value)
    {
    }
}

final class TestEnvironmentApplication extends Application
{
    public ?string $configuredName = null;
    public ?string $serviceName = null;

    public function __construct(private readonly string $environmentFile)
    {
        parent::__construct();
    }

    protected function environmentFiles(): array
    {
        return [$this->environmentFile];
    }

    protected function configure(Config $config): void
    {
        $this->configuredName = $config->options(TestEnvironmentOptions::class)->name;
    }

    protected function services(ServiceProviderRegistry $providers): void
    {
        $this->serviceName = $this->getConfig()->options(TestEnvironmentOptions::class)->name;
    }
}

#[Options(prefix: "ATOM_APP_TEST_")]
final readonly class TestEnvironmentOptions
{
    public function __construct(public string $name = "")
    {
    }
}

final readonly class TestOptionsConsumer
{
    public function __construct(public TestEnvironmentOptions $options)
    {
    }
}

final class TestPathApplication extends Application
{
    public ?string $configuredName = null;

    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function rootPath(): string
    {
        return $this->root;
    }

    protected function configure(Config $config): void
    {
        $this->configuredName = $config->options(TestEnvironmentOptions::class)->name;
    }
}

final class TestPageApplication extends Application
{
    public bool $pageRouteWasAvailableInBootstrap = false;

    public function __construct(private readonly string $pageDirectory)
    {
        parent::__construct();
    }

    protected function configurePaths(Paths $paths): void
    {
        $paths->alias("pages", $this->pageDirectory);
    }

    protected function pages(PageRegistry $pages): void
    {
        $pages->directory("@pages", "/app");
    }

    protected function bootstrap(Injector $injector): void
    {
        $this->pageRouteWasAvailableInBootstrap = (new RouteMatcher($this->getRouter()))
            ->match("GET", "/app/hello-page")
            ->isFound();
    }
}

final class TestComponentApplication extends Application
{
    public bool $componentWasAvailableInBootstrap = false;

    protected function components(ComponentRegistry $components): void
    {
        $components->register("App.Test", TestApplicationComponent::class);
    }

    protected function bootstrap(Injector $injector): void
    {
        $this->componentWasAvailableInBootstrap = $injector
            ->get(ComponentRegistry::class)
            ->has("App.Test");
    }
}

final class TestCorsApplication extends Application
{
    protected function configure(Config $config): void
    {
        $config->set(new CorsOptions(allowedOrigins: "https://app.example.com"));
    }

    protected function middlewares(MiddlewareRegistry $middlewares): void
    {
        $middlewares
            ->add(RequestIdMiddleware::class)
            ->add(CorsMiddleware::class);
    }

    protected function bootstrap(Injector $injector): void
    {
        Route::get("/api", fn(): string => "api");
        Route::get("/explode", static function (): never {
            throw new \RuntimeException("route failed");
        });
    }
}

final class TestApplicationComponent implements ComponentInterface
{
    public function render(): string
    {
        return "";
    }
}

final class TestResponseEmitter implements ResponseEmitterInterface
{
    public ?Response $response = null;

    public function emit(Response $response): void
    {
        $this->response = $response;
    }
}
