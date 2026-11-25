# Request Middleware

## Request IDs

`RequestIdMiddleware` makes a correlation ID available to downstream code and adds it to the response. A valid incoming `X-Request-Id` is preserved by default; missing, malformed, oversized, or untrusted values are replaced with a random 128-bit hexadecimal ID.

```env
REQUEST_ID_HEADER_NAME=X-Request-Id
REQUEST_ID_TRUST_INCOMING=true
REQUEST_ID_MAX_LENGTH=128
```

Set `REQUEST_ID_TRUST_INCOMING=false` unless a trusted edge proxy replaces client-provided IDs. Response-specific request ID headers are preserved.

## Request body limits

`RequestBodyLimitMiddleware` rejects oversized requests with `413 Content Too Large`. It checks both `Content-Length` and the buffered body. Malformed or ambiguous content lengths return `400 Bad Request`.

```env
REQUEST_BODY_MAX_BYTES=10485760
```

The default is 10 MiB. Set the limit to `0` to disable it. PHP and the web server may enforce their own upload and request limits before Atom receives the request; production settings should be aligned across all layers.

## Global registration

```php
use Atom\Http\MiddlewareRegistry;
use Atom\Http\RequestBodyLimitMiddleware;
use Atom\Http\RequestIdMiddleware;

protected function middlewares(MiddlewareRegistry $middlewares): void
{
    $middlewares
        ->add(RequestIdMiddleware::class)
        ->add(RequestBodyLimitMiddleware::class);
}
```

Register the request ID middleware early so short-circuit and framework-rendered exception responses from middleware registered after it also receive an ID. `REQUEST_ID_MAX_LENGTH` must be at least 32 characters so generated 128-bit identifiers always fit.

## Check a body limit with a pickup note

In a fresh Atom Starter app, register both middleware classes as shown above in `app/Application.php`. Set `REQUEST_BODY_MAX_BYTES=16` in the project's `.env`. This small limit applies across the demo app and makes the boundary easy to test.

Add these imports to `app/Application.php` alongside the middleware imports.

```php
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Router\Route;
```

Register a pickup-note preview in `bootstrap()`. If the hook already exists, add the route to its body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::post("/pickup/note-preview", static function (Request $request, Response $response): Response {
        return $response->json(["note" => $request->getBody()]);
    });
}
```

Run `php atom serve` from the project root. In a second PowerShell terminal, send a 16-byte plain-text note, then send the same note with one extra character.

```powershell
$url = "http://127.0.0.1:8020/pickup/note-preview"
curl.exe -i -H "Content-Type: text/plain" --data-binary "Ready for pickup" $url
curl.exe -i -H "Content-Type: text/plain" --data-binary "Ready for pickup!" $url
```

The first response is HTTP `200` with `{"note":"Ready for pickup"}`. The second is HTTP `413` with the plain-text body `Request body is too large.` The middleware checks the size in bytes before the preview route runs. Both responses include `X-Request-Id` because the request ID middleware runs first.
