# Middlewares

[Atom Framework](Index.md)

Middlewares use Atom's small request/response wrappers and a simple request-handler pipeline.

## Global middleware

Global middleware runs before route matching and can short-circuit the request. Register it from the application:

```php
use Atom\Http\MiddlewareRegistry;

protected function middlewares(MiddlewareRegistry $middlewares): void
{
    $middlewares->add(TrustedProxyMiddleware::class);
    $middlewares->add(TrustedHostMiddleware::class);
    $middlewares->add(RequestIdMiddleware::class);
    $middlewares->add(RequestBodyLimitMiddleware::class);
    $middlewares->add(CorsMiddleware::class);
    $middlewares->add(RateLimitMiddleware::class);
}
```

Order is significant. Route and router-group middleware continue to run after a route has matched.

## Example

### Mark French catalogue responses with their language

Suppose a shop serves a French catalogue under `/fr/catalogue`. Use middleware to set `Content-Language: fr` on every response from that route group. A client can then discover the catalogue language by reading the response headers.

In an Atom Starter app, create `app/Middlewares/FrenchContentLanguage.php`.

```php
<?php

declare(strict_types=1);

namespace App\Middlewares;

use Atom\Http\MiddlewareInterface;
use Atom\Http\Request;
use Atom\Http\RequestHandlerInterface;
use Atom\Http\Response;

final class FrenchContentLanguage implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);
        return $response->header("Content-Language", "fr");
    }
}
```

`handle()` runs the rest of the pipeline, including the matched route. Setting the header on the returned response preserves the route's status and body.

Add these imports to `app/Application.php`.

```php
use App\Middlewares\FrenchContentLanguage;
use Atom\Di\Injector;
use Atom\Router\Route;
use Atom\Router\Router;
```

Register the routes in `bootstrap()`. If the method already exists, add the registrations to its body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::group("/fr/catalogue", static function (Router $group): void {
        $group->middleware(FrenchContentLanguage::class);

        Route::get("/carnet", static fn(): array => ["nom" => "Carnet"]);
        Route::get("/crayon", static fn(): array => ["nom" => "Crayon"]);
    });

    Route::get("/status", static fn(): array => ["ready" => true]);
}
```

Run `php atom serve` from the project root. In a second PowerShell terminal, include the headers when fetching the two products and the status endpoint.

```powershell
curl.exe -i http://127.0.0.1:8020/fr/catalogue/carnet
curl.exe -i http://127.0.0.1:8020/fr/catalogue/crayon
curl.exe -i http://127.0.0.1:8020/status
```

Both catalogue responses return HTTP `200` and `Content-Language: fr`, with `{"nom":"Carnet"}` and `{"nom":"Crayon"}` as their respective bodies. The status endpoint sits outside the group and returns `{"ready":true}` without the catalogue header.

If only the notebook response needs the header, remove `$group->middleware(...)` and attach the middleware directly to that route inside the group.

```php
Route::get("/carnet", static fn(): array => ["nom" => "Carnet"])
    ->middleware(FrenchContentLanguage::class);
```

Repeat the two catalogue requests. The header now appears only on `/fr/catalogue/carnet`.
