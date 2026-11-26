# Error Pages

Atom registers a standalone error pages module by default. It renders framework-generated `404` and `405` responses and unhandled exceptions using Atom's core template renderer and components private to ErrorPages.

When the optional Logging module provides a `LoggerInterface`, unhandled exceptions are recorded automatically before the response is rendered. Log context includes the error reference, configured request ID, method, path, effective client IP, matched route metadata, exception, and previous exception when available. The same exception object is logged at most once per request, and logging failures never prevent error-page rendering.

The handler renders an `ErrorPageView` with a standalone `PageRenderer`. Atom finds `Views/ErrorPageView.atom.html` next to the Page class and wraps its output in the module-local `ErrorPages.Layout` component. The layout embeds `Resources/error.css` through `<ErrorPages.Styles />`. A private component registry and a fresh injection context keep error rendering independent of application components and request state. If the error handler cannot be resolved or rendering fails, the application falls back to a plain-text `500 Internal Server Error` response.

## Configuration

Error diagnostics are disabled by default. Enable them locally with:

```env
APP_DEBUG=true
```

Normal status pages contain only a friendly message. Exception pages also contain an error reference, and debug pages show the request method and path, exception type, message, file, line, and trace.

Do not enable debug output in production.

## JSON Errors

Requests whose `Accept` header contains `application/json` or a `+json` media type receive JSON:

```json
{
  "error": {
    "status": 404,
    "title": "Page not found",
    "message": "The page you were looking for could not be found."
  }
}
```

Debug details are included under `error.debug` only when `APP_DEBUG` is enabled.

## Access failures

Atom exposes stable access failure codes in the `X-Atom-Error-Code` response header. JSON clients also receive the code in `error.code`. Use the code when handling the response programmatically.

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | `authentication_required` | The route requires a signed-in identity. |
| 403 | `invalid_csrf_token` | The request failed CSRF validation. |
| 403 | `permission_denied` | The request was denied by an access rule. |

The default error renderer assigns the authentication and permission codes to HTTP exceptions with status 401 and 403. Application permission middleware can return `AccessFailure::PermissionDenied->response($request)` using `Atom\Http\AccessFailure`. Authentication, guest-only, and CSRF middleware return plain text for browser requests and structured JSON for clients that request it. These responses use `Cache-Control: no-store`.

## HTTP Exceptions

### Tell clients when to retry a menu request

Suppose a café publishes today's menu as a text file, one dish per line. Until the file is ready, its menu endpoint should return HTTP `503` with a public message and a `Retry-After` header. An empty menu would look like a successful publication.

In a fresh Atom Starter app, add these imports to `app/Application.php`.

```php
use Atom\Di\Injector;
use Atom\Http\HttpException;
use Atom\Http\Response;
use Atom\Router\Route;
```

Register the endpoint in `bootstrap()`. If the method already exists, add the route to its body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::get("/api/menu", static function (Response $response): Response {
        $path = dirname(__DIR__) . "/storage/daily-menu.txt";
        $dishes = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($dishes === false) {
            throw new HttpException(503, "Today's menu is being prepared.", ["Retry-After" => "60"]);
        }

        return $response->json(["dishes" => $dishes]);
    })->api();
}
```

Run `php atom serve` in the project root. Before creating the menu file, request the endpoint from a second PowerShell terminal.

```powershell
curl.exe -i -H "Accept: application/json" http://127.0.0.1:8020/api/menu
```

Look for status `503` and `Retry-After: 60`. The header asks the client to wait 60 seconds before retrying. In the JSON body, `error.message` contains `Today's menu is being prepared.` and `error.id` identifies this failure. Change the `Accept` header to `text/html` to see the same message on the HTML error page.

From the same project root, publish a menu and repeat the request.

```powershell
"Tomato soup", "Mushroom pie" | Set-Content -Encoding ascii storage/daily-menu.txt
curl.exe -i -H "Accept: application/json" http://127.0.0.1:8020/api/menu
```

The next request returns HTTP `200` with the published dishes.

```json
{"dishes":["Tomato soup","Mushroom pie"]}
```

Unexpected exceptions always produce a generic `500` message outside debug mode.

## Customizing

The default module is registered before application modules. To customize it, copy the small module and handler classes into the application, change the HTML or behavior, and bind the same interface from an application module:

```php
use Atom\Http\ErrorPageHandlerInterface;

public function register(ModuleContext $context): void
{
    $context->bind(ErrorPageHandlerInterface::class)
        ->to(AppErrorPageHandler::class)
        ->scoped();
}
```

Register the application module normally from `Application::modules()`. Because it is registered after Atom's default, its handler is used for subsequent requests.

Custom handlers should remain independent of services that may have caused the original failure. Core always retains the final plain-text fallback.
