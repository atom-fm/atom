<?php

declare(strict_types=1);

namespace Atom\Tests\Module;

use Atom\Application;
use Atom\Di\Injector;
use Atom\Di\InjectionContext;
use Atom\Http\ErrorPageHandlerInterface;
use Atom\Http\HttpException;
use Atom\Http\Request;
use Atom\Http\RequestIdOptions;
use Atom\Http\Response;
use Atom\Logging\LoggerInterface;
use Atom\Module\ModuleContext;
use Atom\Module\ModuleInterface;
use Atom\Module\ModuleRegistry;
use Atom\Modules\ErrorPages\DefaultErrorPageHandler;
use Atom\Modules\ErrorPages\ErrorPagesOptions;
use Atom\Router\Route;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\ComponentRegistry;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ErrorPagesModuleTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
        Route::clearRouter();
        putenv("APP_DEBUG");
        unset($_ENV["APP_DEBUG"], $_SERVER["APP_DEBUG"]);
    }

    public function testDefaultNotFoundPageIsStandaloneAndProductionSafe(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions());

        $response = $handler->forStatus(404, new Request("GET", "/missing"));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame("text/html; charset=utf-8", $response->headers()->get("Content-Type"));
        $this->assertSame("no-store", $response->headers()->get("Cache-Control"));
        $this->assertStringContainsString("<!doctype html>", $response->getContent());
        $stylesheet = file_get_contents(__DIR__ . "/../../src/Modules/ErrorPages/Resources/error.css");
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("<style>" . $stylesheet . "</style>", $response->getContent());
        $this->assertStringContainsString("HTTP 404", $response->getContent());
        $this->assertStringContainsString("Page not found", $response->getContent());
        $this->assertStringNotContainsString("Reference:", $response->getContent());
        $this->assertStringNotContainsString("Diagnostics", $response->getContent());
        $this->assertStringNotContainsString("<script", $response->getContent());
        $this->assertStringNotContainsString("<link", $response->getContent());
    }

    public function testDebugStatusPageIncludesRequestDiagnosticsAndAllowedMethods(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions(debug: true));

        $response = $handler->forStatus(405, new Request("DELETE", "/articles"), ["Allow" => "GET, POST"]);

        $this->assertSame(405, $response->getStatus());
        $this->assertSame("GET, POST", $response->headers()->get("Allow"));
        $this->assertStringContainsString("Diagnostics", $response->getContent());
        $this->assertStringContainsString("DELETE", $response->getContent());
        $this->assertStringContainsString("/articles", $response->getContent());
        $this->assertStringContainsString("GET, POST", $response->getContent());
        $this->assertStringNotContainsString("<summary>Stack trace</summary>", $response->getContent());
    }

    public function testJsonErrorsFollowAcceptHeader(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions());
        $request = new Request("GET", "/missing", headers: ["Accept" => "application/problem+json"]);

        $response = $handler->forStatus(404, $request);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame("application/json", $response->headers()->get("Content-Type"));
        $this->assertSame(404, $payload["error"]["status"] ?? null);
        $this->assertSame("Page not found", $payload["error"]["title"] ?? null);
        $this->assertArrayNotHasKey("id", $payload["error"] ?? []);
        $this->assertArrayNotHasKey("debug", $payload["error"] ?? []);
    }

    public function testPermissionDenialKeepsTheSameCodeInHtmlAndJson(): void
    {
        $app = new ErrorTestApplication();
        $html = $app->handle(new Request("GET", "/denied", headers: ["Accept" => "text/html"]));
        $json = $app->handle(new Request("GET", "/denied", headers: ["Accept" => "application/json"]));
        $payload = json_decode($json->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(403, $html->getStatus());
        $this->assertSame("permission_denied", $html->headers()->get("X-Atom-Error-Code"));
        $this->assertSame("text/html; charset=utf-8", $html->headers()->get("Content-Type"));
        $this->assertStringContainsString("Permission denied.", $html->getContent());
        $this->assertSame(403, $json->getStatus());
        $this->assertSame("permission_denied", $json->headers()->get("X-Atom-Error-Code"));
        $this->assertSame("application/json", $json->headers()->get("Content-Type"));
        $this->assertSame("permission_denied", $payload["error"]["code"]);
    }

    public function testProductionExceptionPageDoesNotExposeExceptionDetails(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions());

        $response = $handler->forException(
            new RuntimeException("database password is secret"),
            new Request("GET", "/explode")
        );

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString("Something went wrong", $response->getContent());
        $this->assertStringContainsString("Reference: err_", $response->getContent());
        $this->assertStringNotContainsString("database password is secret", $response->getContent());
        $this->assertStringNotContainsString(RuntimeException::class, $response->getContent());
    }

    public function testDebugExceptionPageEscapesAndDisplaysDiagnostics(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions(debug: true));

        $response = $handler->forException(
            new RuntimeException("Failed <unsafe>"),
            new Request("POST", "/explode")
        );

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString("RuntimeException", $response->getContent());
        $this->assertStringContainsString("Failed &lt;unsafe&gt;", $response->getContent());
        $this->assertStringContainsString("<summary>Stack trace</summary>", $response->getContent());
        $this->assertStringNotContainsString("Failed <unsafe>", $response->getContent());
        $this->assertStringContainsString("/explode", $response->getContent());
    }

    public function testHttpExceptionControlsStatusPublicMessageAndHeaders(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions());

        $response = $handler->forException(
            new HttpException(429, "Slow down.", ["Retry-After" => "30"]),
            new Request("POST", "/actions")
        );

        $this->assertSame(429, $response->getStatus());
        $this->assertSame("30", $response->headers()->get("Retry-After"));
        $this->assertStringContainsString("Slow down.", $response->getContent());
    }

    public function testApplicationCatchesUnhandledRouteExceptions(): void
    {
        $app = new ErrorTestApplication();

        $response = $app->handle(new Request("GET", "/explode"));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString("Something went wrong", $response->getContent());
        $this->assertStringNotContainsString("route exploded", $response->getContent());
    }

    public function testApplicationReadsDebugModeFromEnvironment(): void
    {
        putenv("APP_DEBUG=true");
        $_ENV["APP_DEBUG"] = "true";

        $app = new ErrorTestApplication();
        $response = $app->handle(new Request("GET", "/explode"));

        $this->assertStringContainsString("Diagnostics", $response->getContent());
        $this->assertStringContainsString("route exploded", $response->getContent());
    }

    public function testErrorPageUsesItsOwnComponentsAfterAnApplicationViewFails(): void
    {
        $_ENV["APP_DEBUG"] = "true";
        $app = new ErrorTestApplication();
        $app->initialize();
        $components = $app->getInjector()->get(ComponentRegistry::class);
        $this->assertFalse($components->has("ErrorPages.Layout"));
        $this->assertFalse($components->has("ErrorPages.Summary"));
        $this->assertFalse($components->has("ErrorPages.Diagnostics"));

        $components->register("ErrorPages.Layout", BrokenAppSummary::class);
        $components->register("ErrorPages.Summary", BrokenAppSummary::class);
        Route::get("/broken-view", static fn(ViewRenderer $renderer): string =>
            $renderer->render((new ViewParser())->parse("<ErrorPages.Summary />"))
        );

        $response = $app->handle(new Request("GET", "/broken-view"));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString("<title>500 · Something went wrong</title>", $response->getContent());
        $this->assertStringContainsString("<h1>Something went wrong</h1>", $response->getContent());
        $this->assertStringContainsString("<dd>Application summary failed</dd>", $response->getContent());
    }

    public function testApplicationModuleCanReplaceDefaultErrorHandler(): void
    {
        $app = new CustomErrorTestApplication();

        $response = $app->handle(new Request("GET", "/missing"));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame("custom:404", $response->getContent());

        $exceptionResponse = $app->handle(new Request("GET", "/explode"));

        $this->assertSame(500, $exceptionResponse->getStatus());
        $this->assertSame("custom:500", $exceptionResponse->getContent());
    }

    public function testBrokenCustomExceptionHandlerUsesCoreFallback(): void
    {
        $app = new BrokenErrorTestApplication();

        $response = $app->handle(new Request("GET", "/explode"));

        $this->assertSame(500, $response->getStatus());
        $this->assertSame("text/plain; charset=utf-8", $response->headers()->get("Content-Type"));
        $this->assertSame("Internal Server Error", $response->getContent());
    }

    public function testExceptionLoggingIncludesRequestAndRouteContextOnlyOnce(): void
    {
        $logger = new RecordingErrorPageLogger();
        $context = new InjectionContext();
        $route = RouteEntry::get("/explode", static fn(): string => "never")->name("explode");
        $context->set(MatchedRoute::class, new MatchedRoute($route));
        $handler = new DefaultErrorPageHandler(
            new ErrorPagesOptions(),
            $logger,
            $context,
            new RequestIdOptions(headerName: "X-Correlation-Id")
        );
        $previous = new RuntimeException("root cause");
        $exception = new RuntimeException("route failed", previous: $previous);
        $request = new Request("POST", "/explode", serverParams: [
            "REMOTE_ADDR" => "198.51.100.7",
        ], headers: ["X-Correlation-Id" => "request_123"]);

        $handler->forException($exception, $request);
        $handler->forException($exception, $request);

        $this->assertCount(1, $logger->errors);
        $log = $logger->errors[0];
        $this->assertSame("Unhandled exception", $log["message"]);
        $this->assertSame("request_123", $log["context"]["request_id"] ?? null);
        $this->assertSame("198.51.100.7", $log["context"]["client_ip"] ?? null);
        $this->assertSame("POST", $log["context"]["method"] ?? null);
        $this->assertSame("/explode", $log["context"]["path"] ?? null);
        $this->assertSame("explode", $log["context"]["route_name"] ?? null);
        $this->assertSame("/explode", $log["context"]["route_path"] ?? null);
        $this->assertSame("GET", $log["context"]["route_methods"] ?? null);
        $this->assertSame($exception, $log["context"]["exception"] ?? null);
        $this->assertSame($previous, $log["context"]["previous_exception"] ?? null);
        $this->assertStringStartsWith("err_", (string) ($log["context"]["error_id"] ?? ""));
    }

    public function testLoggingFailureDoesNotPreventExceptionRendering(): void
    {
        $handler = new DefaultErrorPageHandler(new ErrorPagesOptions(), new BrokenErrorPageLogger());

        $response = $handler->forException(
            new RuntimeException("route failed"),
            new Request("GET", "/explode")
        );

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString("Something went wrong", $response->getContent());
    }
}

class ErrorTestApplication extends Application
{
    protected function bootstrap(Injector $injector): void
    {
        Route::get("/explode", static function (): never {
            throw new RuntimeException("route exploded");
        });
        Route::get("/denied", static function (): never {
            throw new HttpException(403, "Permission denied.");
        });
    }
}

final class BrokenAppSummary implements ComponentInterface
{
    public function render(): never
    {
        throw new RuntimeException("Application summary failed");
    }
}

final class CustomErrorTestApplication extends ErrorTestApplication
{
    protected function modules(ModuleRegistry $modules): void
    {
        $modules->add(new CustomErrorModule());
    }
}

final class BrokenErrorTestApplication extends ErrorTestApplication
{
    protected function modules(ModuleRegistry $modules): void
    {
        $modules->add(new BrokenErrorModule());
    }
}

final readonly class CustomErrorModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->bind(ErrorPageHandlerInterface::class)->toValue(new CustomErrorHandler());
    }
}

final readonly class BrokenErrorModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->bind(ErrorPageHandlerInterface::class)->toValue(new BrokenErrorHandler());
    }
}

final readonly class CustomErrorHandler implements ErrorPageHandlerInterface
{
    public function forStatus(int $status, Request $request, array $headers = []): Response
    {
        return (new Response())->status($status)->withHeaders($headers)->content("custom:{$status}");
    }

    public function forException(Throwable $exception, Request $request): Response
    {
        return (new Response())->status(500)->content("custom:500");
    }
}

final readonly class BrokenErrorHandler implements ErrorPageHandlerInterface
{
    public function forStatus(int $status, Request $request, array $headers = []): Response
    {
        throw new RuntimeException("error renderer failed");
    }

    public function forException(Throwable $exception, Request $request): Response
    {
        throw new RuntimeException("error renderer failed");
    }
}

final class RecordingErrorPageLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $errors = [];

    public function info(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = ["message" => $message, "context" => $context];
    }
}

final readonly class BrokenErrorPageLogger implements LoggerInterface
{
    public function info(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
        throw new RuntimeException("log unavailable");
    }
}
