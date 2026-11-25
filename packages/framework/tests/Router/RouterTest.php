<?php

declare(strict_types=1);

namespace Atom\Tests\Router;

use Atom\Router\Route;
use Atom\Router\RouteEntry;
use Atom\Router\Router;
use Atom\Router\RouteAction;
use Atom\Router\Attributes\ApiController;
use Atom\Router\Attributes\Controller;
use Atom\Router\Attributes\Delete;
use Atom\Router\Attributes\Get;
use Atom\Router\Attributes\Head;
use Atom\Router\Attributes\HttpRoute;
use Atom\Router\Attributes\Options;
use Atom\Router\Attributes\Patch;
use Atom\Router\Attributes\Put;
use Atom\Router\Attributes\Post;
use PHPUnit\Framework\TestCase;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Http\MiddlewareInterface;
use Atom\Http\RequestHandlerInterface;
use RuntimeException;

final class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Route::clearRouter();
    }

    public function testGeneratesNamedUrlsForClosuresControllersAndPages(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::get("/", static fn(): array => [])->name("home");
        Route::group("/v1", static function (): void {
            Route::get("/articles/{slug}", static fn(): array => [])->name("articles.show");
            Route::head("/articles/{slug}", static fn(): array => [])->name("articles.show");
            Route::attachTo("/staff", PrefixedAnnotatedTestController::class);
        });

        $page = new class extends \Atom\Page\Page {};
        (new \Atom\Page\PageRouteRegistrar())->register($router, [
            new \Atom\Page\PageDescriptor("/products/{id}", $page::class, "products.show"),
        ], "/catalog");

        self::assertSame("/", Route::url("home"));
        self::assertSame(
            "/v1/articles/tea%20%26%2F%23?view=full%20text&tags%5B0%5D=new",
            Route::url("articles.show", ["slug" => "tea &/#"], query: ["view" => "full text", "tags" => ["new"]])
        );
        self::assertSame("/v1/staff/api/users", Route::url("users.list"));
        self::assertSame("/catalog/products/42", Route::url("products.show", ["id" => 42]));
    }

    public function testGeneratedWildcardUrlPreservesPathSegments(): void
    {
        $router = new Router();
        $router->add(RouteEntry::get("/files/{path*}", static fn(): array => [])->name("files"));

        $url = $router->url("files", ["path" => "images/Spring sale #1.png"]);
        self::assertSame("/files/images/Spring%20sale%20%231.png", $url);

        $match = (new \Atom\Router\RouteMatcher($router))->match("GET", $url);
        self::assertSame(["path" => "images/Spring sale #1.png"], $match->matchedRoute?->getRouteParams());
    }

    public function testRejectsUnknownRouteNames(): void
    {
        $this->expectExceptionMessage("Unknown route name 'missing'.");
        (new Router())->url("missing");
    }

    public function testRejectsMissingAndUnexpectedRouteParameters(): void
    {
        $router = new Router();
        $router->add(RouteEntry::get("/articles/{slug}", static fn(): array => [])->name("articles.show"));

        try {
            $router->url("articles.show");
            self::fail("Missing parameters should fail URL generation.");
        } catch (\InvalidArgumentException $exception) {
            self::assertSame("Missing parameter 'slug' for route 'articles.show'.", $exception->getMessage());
        }

        $this->expectExceptionMessage("Unknown parameter 'extra' for route 'articles.show'.");
        $router->url("articles.show", ["slug" => "draft", "extra" => 1]);
    }

    public function testRejectsAmbiguousRouteNames(): void
    {
        $router = new Router();
        $router->add(RouteEntry::get("/first", static fn(): array => [])->name("duplicate"));
        $router->add(RouteEntry::get("/second", static fn(): array => [])->name("duplicate"));

        $this->expectExceptionMessage("Route name 'duplicate' matches more than one path.");
        $router->url("duplicate");
    }

    public function testRejectsDotSegmentsInWildcardParameters(): void
    {
        $router = new Router();
        $router->add(RouteEntry::get("/files/{path*}", static fn(): array => [])->name("files"));

        $this->expectExceptionMessage("Parameter 'path' for route 'files' contains an empty or dot segment.");
        $router->url("files", ["path" => "images/../private"]);
    }

    public function testStaticRouteFacadeRegistersRouteOnSharedRouter(): void
    {
        $router = new Router();
        Route::setRouter($router);

        $route = Route::get("/users", function () {
            return "users";
        });

        $routes = $router->getAllRoutes();

        $this->assertInstanceOf(RouteEntry::class, $route);
        $this->assertSame($route, $routes[0]);
        $this->assertSame("GET", $route->getMethod());
        $this->assertSame("/users", $route->getFullPath());
        $this->assertTrue($route->isClosure());
    }

    public function testStaticRouteFacadeRegistersHeadRoute(): void
    {
        $router = new Router();
        Route::setRouter($router);

        $route = Route::head("/users", function () {
            return "";
        });

        $this->assertSame("HEAD", $route->getMethod());
        $this->assertSame([$route], $router->getAllRoutes());
    }

    public function testRouterAddsRouteEntry(): void
    {
        $router = new Router();
        $entry = RouteEntry::create(
            "GET",
            "/health",
            RouteAction::closure(function () {
                return "ok";
            })
        );

        $result = $router->add($entry);

        $this->assertSame($entry, $result);
        $this->assertSame([$entry], $router->getRoutes());
    }

    public function testRouterCanBeConstructedWithPath(): void
    {
        $router = new Router("/api");

        $this->assertSame("/api", $router->getFullPath());
    }

    public function testRouterMountsChildRouterAsRouteEntry(): void
    {
        $root = new Router();
        $api = new Router("/api");

        $api->add(RouteEntry::create(
            "GET",
            "/users",
            RouteAction::closure(function () {
                return [];
            })
        ));

        $mount = $root->add($api);
        $routes = $root->getAllRoutes();

        $this->assertSame($api, $mount);
        $this->assertCount(1, $routes);
        $this->assertSame("/api/users", $routes[0]->getFullPath());
    }

    public function testStaticRouteFacadeRegistersRoutesInsideGroup(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::group("/admin", function () {
            Route::get("users", function () {
                return "admin users";
            });
        });

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame("/admin/users", $routes[0]->getFullPath());
    }

    public function testStaticRouteFacadeRestoresParentRouterAfterGroup(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::group("/admin", function () {
            Route::get("users", function () {
                return "admin users";
            });
        });

        Route::get("/health", function () {
            return "ok";
        });

        $routes = $router->getAllRoutes();

        $this->assertSame("/admin/users", $routes[0]->getFullPath());
        $this->assertSame("/health", $routes[1]->getFullPath());
    }

    public function testNestedGroupsComposeFullPath(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::group("/api", function () {
            Route::group("v1", function () {
                Route::get("users", function () {
                    return [];
                });
            });
        });

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame("/api/v1/users", $routes[0]->getFullPath());
    }

    public function testStaticRouteFacadeUsesControllerGroupForActionShorthand(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::controller(self::class, function () {
            Route::get("/users", "users");
        });

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(self::class, $routes[0]->getController());
        $this->assertSame("users", $routes[0]->getMethodName());
        $this->assertSame("/users", $routes[0]->getFullPath());
    }

    public function testStaticRouteFacadeUsesPathAndControllerGroupForActionShorthand(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::group("/admin", function () {
            Route::controller(self::class, function () {
                Route::get("users", "users");
            });
        });

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(self::class, $routes[0]->getController());
        $this->assertSame("users", $routes[0]->getMethodName());
        $this->assertSame("/admin/users", $routes[0]->getFullPath());
    }

    public function testNestedGroupsInheritController(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::controller(self::class, function () {
            Route::group("/admin", function () {
                Route::get("users", "users");
            });
        });

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(self::class, $routes[0]->getController());
        $this->assertSame("/admin/users", $routes[0]->getFullPath());
    }

    public function testStaticRouteFacadeRequiresControllerForActionShorthand(): void
    {
        $router = new Router();
        Route::setRouter($router);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No route controller has been configured for controller action shorthand.");

        Route::get("/users", "users");
    }

    public function testStaticRouteFacadeAttachesAnnotatedControllerToCurrentRouter(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::attach(AnnotatedTestController::class);

        $routes = $router->getAllRoutes();

        $this->assertCount(2, $routes);
        $this->assertSame("/health", $routes[0]->getFullPath());
        $this->assertSame("GET", $routes[0]->getMethod());
        $this->assertSame(AnnotatedTestController::class, $routes[0]->getController());
        $this->assertSame("health", $routes[0]->getMethodName());
        $this->assertSame("/users", $routes[1]->getFullPath());
        $this->assertSame("POST", $routes[1]->getMethod());
    }

    public function testStaticRouteFacadeAttachesAnnotatedControllerToMountedRouter(): void
    {
        $router = new Router();
        Route::setRouter($router);

        $mounted = Route::attachTo("/api", AnnotatedTestController::class);

        $routes = $router->getAllRoutes();

        $this->assertSame("/api", $mounted->getFullPath());
        $this->assertCount(2, $routes);
        $this->assertSame("/api/health", $routes[0]->getFullPath());
        $this->assertSame("/api/users", $routes[1]->getFullPath());
    }

    public function testStaticRouteFacadeUsesControllerAttributePath(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::attach(PrefixedAnnotatedTestController::class);

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame("/api/users", $routes[0]->getFullPath());
    }

    public function testStaticRouteFacadeCombinesAttachPathWithControllerAttributePath(): void
    {
        $router = new Router();
        Route::setRouter($router);

        $mounted = Route::attachTo("/v1", PrefixedAnnotatedTestController::class);

        $routes = $router->getAllRoutes();

        $this->assertSame("/v1", $mounted->getFullPath());
        $this->assertCount(1, $routes);
        $this->assertSame("/v1/api/users", $routes[0]->getFullPath());
        $this->assertTrue($routes[0]->isApi());
        $this->assertSame("users.list", $routes[0]->getName());
        $this->assertSame("List users", $routes[0]->getTitle());
        $this->assertSame("All active users", $routes[0]->getDescription());
        $outer = new RouterTestMiddleware();
        $mounted->middleware($outer);
        $middlewares = $routes[0]->getMiddlewares();
        $this->assertCount(3, $middlewares);
        $this->assertSame([$outer, RouterTestMiddleware::class], array_slice($middlewares, 0, 2));
        $this->assertInstanceOf(RouterTestMiddleware::class, $middlewares[2]);
    }

    public function testEachRepeatedVerbCreatesAnIndependentEndpoint(): void
    {
        $router = new Router();
        Route::setRouter($router);
        Route::attach(MultiVerbController::class);

        $methods = [];
        foreach ($router->getAllRoutes() as $entry) {
            $methods[$entry->getName()] = $entry->getMethod();
            $this->assertTrue($entry->isApi());
        }
        $this->assertSame([
            "read" => "GET", "alias" => "GET", "create" => "POST", "replace" => "PUT",
            "edit" => "PATCH", "remove" => "DELETE", "headers" => "HEAD", "options" => "OPTIONS",
            "multi" => ["GET", "POST"]
        ], $methods);
    }

    public function testControllerAttributePathHandlesEmptyMethodPath(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::attach(EmptyMethodPathAnnotatedTestController::class);

        $routes = $router->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertFalse($routes[0]->isApi());
        $this->assertSame("/api", $routes[0]->getFullPath());
    }

    public function testRouteEntryMergesRouterAndEntryMiddlewares(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::group("/admin", function (Router $router) {
            $router->middleware("auth");
            Route::get("users", function () {
                return "admin users";
            })->middleware("log");
        });

        $routes = $router->getAllRoutes();

        $this->assertSame(["auth", "log"], $routes[0]->getMiddlewares());
        $this->assertSame(["log"], $routes[0]->getOwnMiddlewares());
    }

    public function testChildRouterUsesLateParentMiddlewares(): void
    {
        $router = new Router();
        Route::setRouter($router);

        Route::group("/admin", function () {
            Route::get("users", function () {
                return "admin users";
            })->middleware("log");
        });

        $router->middleware("auth");

        $routes = $router->getAllRoutes();

        $this->assertSame(["auth", "log"], $routes[0]->getMiddlewares());
    }

    public function testMiddlewareAcceptsClassStringAndInstance(): void
    {
        $router = new Router();
        Route::setRouter($router);
        $middleware = new RouterTestMiddleware();

        $router->middleware(RouterTestMiddleware::class);
        Route::get("/users", function () {
            return [];
        })->middleware($middleware);

        $routes = $router->getAllRoutes();

        $this->assertSame([RouterTestMiddleware::class, $middleware], $routes[0]->getMiddlewares());
    }

    public function testRouteEntryKeepsMetadata(): void
    {
        $router = new Router();
        Route::setRouter($router);
        $metadata = new \stdClass();

        $route = Route::get("/users", function () {
            return "users";
        })->metadata($metadata);

        $this->assertSame([$metadata], $route->getMetadata());
        $this->assertSame($metadata, $route->getMetadataOfType(\stdClass::class));
        $this->assertSame([$metadata], $route->getMetadataArrayOfType(\stdClass::class));
    }

    public function testStaticRouteFacadeRequiresSharedRouter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No shared router has been configured.");

        Route::get("/users", function () {
            return "users";
        });
    }
}

final class AnnotatedTestController
{
    #[Get("/health")]
    public function health(): string
    {
        return "ok";
    }

    #[Post("/users")]
    public function create(): array
    {
        return [];
    }
}

#[ApiController("/api", middleware: RouterTestMiddleware::class)]
final class PrefixedAnnotatedTestController
{
    #[Get("/users", name: "users.list", middleware: [new RouterTestMiddleware()], title: "List users", description: "All active users")]
    public function users(): array
    {
        return [];
    }
}

#[Controller("/api")]
final class EmptyMethodPathAnnotatedTestController
{
    #[Get("")]
    public function index(): array
    {
        return [];
    }
}

#[ApiController]
final class MultiVerbController
{
    #[Get("/", name: "read")]
    #[Get("/alias", name: "alias")]
    #[Post("/", name: "create")]
    #[Put("/", name: "replace")]
    #[Patch("/", name: "edit")]
    #[Delete("/", name: "remove")]
    #[Head("/", name: "headers")]
    #[Options("/", name: "options")]
    #[HttpRoute(["GET", "POST"], "/multi", name: "multi")]
    public function handle(): void
    {
    }
}

final class RouterTestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        return $handler->handle($request);
    }
}
