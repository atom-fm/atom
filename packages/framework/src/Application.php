<?php

declare(strict_types=1);

namespace Atom;

use Atom\Config\Config;
use Atom\Config\Env;
use Atom\Config\Options;
use Atom\Cache\CacheInterface;
use Atom\Cache\CacheServices;
use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Di\TypeFactory;
use Atom\Di\TypeInfo;
use Atom\Dispatcher\Dispatcher;
use Atom\Dispatcher\DispatcherServices;
use Atom\Dispatcher\ResponseEmitter;
use Atom\Dispatcher\ResponseEmitterInterface;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleServices;
use Atom\Http\Request;
use Atom\Http\CookieJar;
use Atom\Http\ErrorPageHandlerInterface;
use Atom\Http\Response;
use Atom\Http\RequestHandlerInterface;
use Atom\Http\MiddlewareInterface;
use Atom\Http\MiddlewareRegistry;
use Atom\Identity\IdentityServices;
use Atom\Logging\Log;
use Atom\Logging\LoggerInterface;
use Atom\Mail\MailServices;
use Atom\Identity\AuthenticatorInterface;
use Atom\Dispatcher\MiddlewarePipeline;
use Atom\Dispatcher\ExceptionRenderingRequestHandler;
use Atom\Module\ModuleContext;
use Atom\Module\ModuleInterface;
use Atom\Module\ModuleRegistry;
use Atom\Module\ModuleScope;
use Atom\Modules\ComponentStyles\ComponentStylesServices;
use Atom\Modules\ErrorPages\ErrorPages;
use Atom\View\Icons\IconProviderInterface;
use Atom\View\Icons\IconRegistry;
use Atom\Modules\Icons\IconServices;
use Atom\Page\PageRegistry;
use Atom\Publish\PublishServices;
use Atom\Queue\JobRegistry;
use Atom\Queue\QueueServices;
use Atom\Page\PageRouteRegistrar;
use Atom\Page\PageServices;
use Atom\Profiler\Profile;
use Atom\Profiler\Profiler;
use Atom\Router\MatchedRoute;
use Atom\Router\Route;
use Atom\Router\Router;
use Atom\Session\SessionInterface;
use Atom\Session\FlashBag;
use Atom\Session\SessionServices;
use Atom\Security\SecurityServices;
use Atom\Scheduler\Schedule;
use Atom\Scheduler\ScheduleServices;
use Atom\Support\ClockServices;
use Atom\Support\Paths;
use Atom\View\Ast\TemplateNode;
use Atom\View\Component\ComponentRegistry;
use Atom\View\TemplateCache;
use Atom\View\Templates;
use Closure;
use RuntimeException;
use Throwable;

abstract class Application
{
    public static ?Application $app = null;
    private ?ServiceProviderRegistry $providers = null;
    private ?Config $config = null;
    private ?Paths $paths = null;
    private ?ModuleRegistry $modules = null;
    private ?PageRegistry $pages = null;
    private ?Bootstrappers $bootstrappers = null;
    private ?MiddlewareRegistry $middlewares = null;
    private ?Profiler $bootstrapProfiler = null;
    private ?Profiler $requestProfiler = null;
    private ?Bindings $bindings = null;
    private ?Injector $injector = null;
    private ?InjectionContext $currentContext = null;
    private ?MatchedRoute $lastMatchedRoute = null;
    private bool $initialized = false;
    protected string $baseUrl = "";

    public function __construct()
    {
        if (self::$app !== null) {
            throw new RuntimeException("Application was already initialized");
        }
        static::$app = $this;
        Log::resolveUsing(static function (): ?LoggerInterface {
            $injector = self::$app?->getInjector();

            return $injector?->has(LoggerInterface::class) ? $injector->get(LoggerInterface::class) : null;
        });
        Profile::resolveUsing(static fn(): ?Profiler => self::$app?->activeProfiler());
        Templates::resolveUsing(static function (): ?TemplateCache {
            $injector = self::$app?->injector;

            return $injector?->has(TemplateCache::class) ? $injector->get(TemplateCache::class) : null;
        });
        Templates::instrumentComponentParsingUsing(
            static fn(string $path, string $component, Closure $parse): TemplateNode => Profile::measure(
                "view.parse",
                $parse,
                ["component" => $component, "template" => $path]
            )
        );
    }

    final public function getProviders(): ServiceProviderRegistry
    {
        return $this->providers ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getConfig(): Config
    {
        return $this->config ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getPaths(): Paths
    {
        return $this->paths ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getBindings(): Bindings
    {
        return $this->bindings ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getModules(): ModuleRegistry
    {
        return $this->modules ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getPages(): PageRegistry
    {
        return $this->pages ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getBootstrappers(): Bootstrappers
    {
        return $this->bootstrappers ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getInjector(): Injector
    {
        return $this->injector ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function getProfiler(): Profiler
    {
        return $this->activeProfiler() ?? throw new RuntimeException("Application has not been initialized.");
    }

    private function activeProfiler(): ?Profiler
    {
        return $this->requestProfiler ?? $this->bootstrapProfiler;
    }

    final public function getRouter(): Router
    {
        return $this->getInjector()->get(Router::class, $this->currentContext);
    }

    final public function getCurrentRoute(): ?MatchedRoute
    {
        if ($this->currentContext === null) {
            return $this->lastMatchedRoute;
        }

        $route = $this->currentContext->get(MatchedRoute::class);
        return $route instanceof MatchedRoute ? $route : null;
    }

    final public function getRequest(): Request
    {
        return $this->getInjector()->get(Request::class, $this->currentContext);
    }

    final public function getResponse(): Response
    {
        return $this->getInjector()->get(Response::class, $this->currentContext);
    }

    final public function getSession(): SessionInterface
    {
        return $this->getInjector()->get(SessionInterface::class, $this->currentContext);
    }

    final public function getAuthenticator(): AuthenticatorInterface
    {
        return $this->getInjector()->get(AuthenticatorInterface::class, $this->currentContext);
    }

    final public function getCache(): CacheInterface
    {
        return $this->getInjector()->get(CacheInterface::class, $this->currentContext);
    }

    final public function getFlash(): FlashBag
    {
        return $this->getInjector()->get(FlashBag::class, $this->currentContext);
    }

    final public function getDispatcher(): RequestHandlerInterface
    {
        return $this->getInjector()->get(Dispatcher::class, $this->currentContext);
    }

    final public function getResponseEmitter(): ResponseEmitterInterface
    {
        return $this->getInjector()->get(ResponseEmitterInterface::class, $this->currentContext);
    }

    final public function getConsole(): ConsoleApplication
    {
        return $this->getInjector()->get(ConsoleApplication::class);
    }

    final public function getMiddlewares(): MiddlewareRegistry
    {
        return $this->middlewares ?? throw new RuntimeException("Application has not been initialized.");
    }

    final public function registerModule(ModuleInterface $module, string $basePath = ""): void
    {
        $applicationComponents = $this->getInjector()->get(ComponentRegistry::class);
        $moduleComponents = new ComponentRegistry($applicationComponents);
        $scope = new ModuleScope($module, $basePath, $moduleComponents);

        $module->register(new ModuleContext(
            $this->getRouter(),
            $this->getInjector(),
            $moduleComponents,
            $basePath,
            $this->getBindings(),
            $this->getConfig(),
            $scope
        ));

        $this->getInjector()->get(\Atom\View\Style\ComponentStyleScanner::class)->addRegistry($moduleComponents);
        $moduleComponents->seal();
    }

    final protected function registerDefaultServices(ServiceProviderRegistry $providers): void
    {
        $providers
            ->add(CacheServices::class)
            ->add(ClockServices::class)
            ->add(ConsoleServices::class)
            ->add(ComponentStylesServices::class)
            ->add(DispatcherServices::class)
            ->add(IdentityServices::class)
            ->add(IconServices::class)
            ->add(MailServices::class)
            ->add(PageServices::class)
            ->add(PublishServices::class)
            ->add(QueueServices::class)
            ->add(ScheduleServices::class)
            ->add(SessionServices::class)
            ->add(SecurityServices::class);
    }

    protected function services(ServiceProviderRegistry $providers): void
    {
    }

    protected function modules(ModuleRegistry $modules): void
    {
    }

    protected function components(ComponentRegistry $components): void
    {
    }

    protected function pages(PageRegistry $pages): void
    {
    }

    protected function middlewares(MiddlewareRegistry $middlewares): void
    {
    }

    protected function jobs(JobRegistry $jobs): void
    {
    }

    protected function schedule(Schedule $schedule): void
    {
    }

    protected function configurePaths(Paths $paths): void
    {
    }

    protected function rootPath(): string
    {
        $file = (new \ReflectionClass($this))->getFileName();
        if ($file === false) {
            throw new RuntimeException("Cannot determine application root path.");
        }

        return dirname($file);
    }

    protected function configure(Config $config): void
    {
    }

    /**
     * @return list<string>
     */
    protected function environmentFiles(): array
    {
        return ["@root/.env"];
    }

    protected function bootstrap(Injector $injector): void
    {
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    final protected function path(string $path): string
    {
        return $this->getPaths()->resolve($path);
    }

    final public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $profiler = new Profiler();
        $this->bootstrapProfiler = $profiler;
        $span = $profiler->begin("app.initialize");

        try {
            $root = $this->rootPath();
            $paths = new Paths($root);
            $paths
                ->alias("app", $root . "/app")
                ->alias("public", $root . "/public")
                ->alias("database", $root . "/app/Database")
                ->alias("migrations", $root . "/app/Database/Migrations")
                ->alias("seeders", $root . "/app/Database/Seeders");
            $this->paths = $paths;
            $this->configurePaths($paths);

            foreach ($this->environmentFiles() as $path) {
                Env::loadIfExists($paths->resolve($path));
            }

            $config = Config::fromEnv();
            $this->config = $config;
            $this->configure($config);

            $providers = ServiceProviderRegistry::create();
            $this->registerDefaultServices($providers);
            $this->services($providers);

            $bindings = $providers->bindings();
            $bindings->value(Application::class, $this);
            $bindings->value(Profiler::class, $profiler);
            $bindings->value(Paths::class, $paths);
            $bindings->value(Config::class, $config);
            $bindings->addTypeFactory(TypeFactory::match(
                fn(TypeInfo $type): bool => $type->hasAttribute(Options::class),
                fn(string $className, Injector $injector, InjectionContext $context): object =>
                    $injector->get(Config::class, $context)->options($className)
            ));
            if (static::class !== Application::class) {
                $bindings->value(static::class, $this);
            }
            $bindings->value(ServiceProviderRegistry::class, $providers);
            $bindings->value(Bindings::class, $bindings);

            $this->providers = $providers;
            $this->bindings = $bindings;
            $this->injector = new Injector($bindings);

            $icons = $this->injector->get(IconRegistry::class);
            foreach ($providers->providers() as $provider) {
                if ($provider instanceof IconProviderInterface) {
                    $provider->icons($icons);
                }
            }

            $this->modules = new ModuleRegistry();
            $this->modules->add(ErrorPages::module());
            $this->pages = new PageRegistry();
            $this->bootstrappers = new Bootstrappers();
            $this->middlewares = new MiddlewareRegistry();
            $bindings->value(MiddlewareRegistry::class, $this->middlewares);

            Route::setRouter($this->injector->get(Router::class));

            $this->jobs($this->injector->get(JobRegistry::class));
            $this->schedule($this->injector->get(Schedule::class));

            $this->modules($this->modules);
            foreach ($this->modules->all() as $registration) {
                $this->registerModule($registration->module, $registration->basePath);
            }

            $this->components($this->injector->get(ComponentRegistry::class));
            $this->injector->get(\Atom\View\Style\ComponentStyleScanner::class)->addRegistry($this->injector->get(ComponentRegistry::class));
            $this->middlewares($this->middlewares);

            $this->pages($this->pages);
            $pageRegistrar = new PageRouteRegistrar();
            foreach ($this->pages->directories() as $directory) {
                $pageRegistrar->registerDirectory(
                    $this->getRouter(),
                    $paths->resolve($directory->directory),
                    $directory->pathPrefix,
                    $directory->middlewares
                );
            }

            foreach ($providers->providers() as $provider) {
                if ($provider instanceof BootstrapProviderInterface) {
                    $provider->bootstrappers($this->bootstrappers);
                }
            }

            foreach ($this->bootstrappers->all() as $bootstrapper) {
                $bootstrapper->bootstrap($this->injector);
            }

            $this->bootstrap($this->injector);

            $this->initialized = true;
        } finally {
            $span->end();
        }
    }

    final public function handle(?Request $request = null): Response
    {
        return $this->processRequest($request, emit: false);
    }

    private function processRequest(?Request $request, bool $emit): Response
    {
        $initializing = !$this->initialized;
        try {
            $this->initialize();
        } catch (Throwable $exception) {
            return $this->finishResponse($this->renderException($exception, $request ?? Request::fromGlobals()), $emit);
        }

        $profiler = new Profiler();
        $previousProfiler = $this->requestProfiler;
        $previousContext = $this->currentContext;
        $this->requestProfiler = $profiler;
        $this->currentContext = new InjectionContext();
        $this->currentContext->set(Profiler::class, $profiler);
        if ($request !== null) {
            $this->currentContext->set(Request::class, $request);
        }

        try {
            $response = $this->dispatchRequest($request, $profiler);
            $response = $this->applyCookies($response);
            if ($initializing && $this->bootstrapProfiler !== null) {
                $response->header("X-Atom-Time-App-Initialize", number_format($this->bootstrapProfiler->total("app.initialize"), 2, ".", "") . "ms");
            }
            $this->addProfilerHeaders($response, $profiler);
            return $this->finishResponse($response, $emit);
        } finally {
            if ($previousContext === null) {
                $this->lastMatchedRoute = $this->getCurrentRoute();
            }
            $this->currentContext = $previousContext;
            $this->requestProfiler = $previousProfiler;
        }
    }

    private function dispatchRequest(?Request $request, Profiler $profiler): Response
    {
        try {
            $activeRequest = $this->getRequest();
            $response = $profiler->measure(
                "request.dispatch",
                fn(): Response => (new MiddlewarePipeline(
                    $this->resolveGlobalMiddlewares(),
                    new ExceptionRenderingRequestHandler(
                        $this->getDispatcher(),
                        fn(Throwable $exception, Request $activeRequest): Response =>
                            $this->renderException($exception, $activeRequest)
                    )
                ))->handle($activeRequest)
            );
        } catch (Throwable $exception) {
            $response = $this->renderException($exception, $request ?? Request::fromGlobals());
        }

        try {
            $this->saveSession();
        } catch (Throwable $exception) {
            $response = $this->renderException($exception, $request ?? Request::fromGlobals());
        }
        return $response;
    }

    /** @return MiddlewareInterface[] */
    private function resolveGlobalMiddlewares(): array
    {
        if ($this->middlewares === null || $this->injector === null) {
            return [];
        }

        $resolved = [];
        foreach ($this->middlewares->all() as $middleware) {
            if (is_string($middleware)) {
                $middleware = $this->injector->get($middleware, $this->currentContext);
            }
            if (!$middleware instanceof MiddlewareInterface) {
                throw new RuntimeException("Can't initialize global middleware, unsupported definition.");
            }
            $resolved[] = $middleware;
        }

        return $resolved;
    }

    final public function run(?Request $request = null): Response
    {
        return $this->processRequest($request, emit: true);
    }

    private function finishResponse(Response $response, bool $emit): Response
    {
        if ($emit) {
            $emitter = $this->injector === null ? new ResponseEmitter() : $this->getResponseEmitter();
            $emitter->emit($response);
        }

        return $response;
    }

    private function addProfilerHeaders(Response $response, Profiler $profiler): void
    {
        $response->header("X-Atom-Time", number_format($profiler->totalMs(), 2, ".", "") . "ms");

        foreach ($profiler->summary() as $summary) {
            $baseHeader = "X-Atom-Time-" . str_replace(".", "-", ucwords($summary->name, "."));
            $header = $baseHeader;
            for ($suffix = 2; $response->headers()->has($header); $suffix++) {
                $header = "{$baseHeader}-{$suffix}";
            }

            $value = number_format($summary->totalMs, 2, ".", "") . "ms";
            if ($summary->count > 1) {
                $value .= "; count=" . $summary->count;
            }

            $response->header($header, $value);
        }
    }

    private function saveSession(): void
    {
        if ($this->injector === null || $this->currentContext === null) {
            return;
        }

        $session = $this->injector->get(SessionInterface::class, $this->currentContext);
        if ($session instanceof SessionInterface) {
            $session->save();
        }
    }

    private function applyCookies(Response $response): Response
    {
        if ($this->injector === null || $this->currentContext === null) {
            return $response;
        }

        $cookies = $this->injector->get(CookieJar::class, $this->currentContext);
        return $cookies instanceof CookieJar ? $cookies->apply($response) : $response;
    }

    private function renderException(Throwable $exception, Request $request): Response
    {
        try {
            if ($this->injector === null) {
                return $this->fallbackErrorResponse();
            }

            $handler = $this->injector->get(ErrorPageHandlerInterface::class, $this->currentContext);
            if (!$handler instanceof ErrorPageHandlerInterface) {
                return $this->fallbackErrorResponse();
            }

            return $handler->forException($exception, $request);
        } catch (Throwable) {
            return $this->fallbackErrorResponse();
        }
    }

    private function fallbackErrorResponse(): Response
    {
        return (new Response())
            ->status(500)
            ->header("Content-Type", "text/plain; charset=utf-8")
            ->header("Cache-Control", "no-store")
            ->content("Internal Server Error");
    }
}
