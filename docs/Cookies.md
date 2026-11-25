# Cookies

[Atom Framework](Index.md)

Atom provides separate APIs for incoming request cookies and outgoing response cookies.

## Reading Cookies

`Request::cookies()` returns a read-only `CookieCollection` parsed from the request's `Cookie` header:

```php
$theme = $request->cookies()->string("theme", "system");

if ($request->cookies()->has("consent")) {
    // ...
}
```

Cookie names are preserved exactly, including dots. Values use percent decoding without treating a literal `+` as a space. The raw header remains available through `$request->headers()->get("Cookie")`.

## Creating Cookies

`Cookie` is an immutable value object:

```php
use Atom\Http\Cookie;

$cookie = Cookie::create("theme", "dark")
    ->expiresAfter(3600)
    ->withPath("/")
    ->withSameSite("Lax")
    ->withHttpOnly()
    ->withSecure();
```

Cookie values are encoded safely when the `Set-Cookie` header is built. `SameSite=None` requires `Secure`.

## Setting Cookies on a Response

When code owns the response it returns, attach the cookie directly:

```php
return $response
    ->cookie($cookie)
    ->content("Preferences saved.");
```

Delete a cookie using the same path and domain with which it was created:

```php
return $response->removeCookie("theme", path: "/");
```

## Remember a reader's theme

Give a reader a way to select dark mode and later return to the system theme. The example stores the preference for a week. Each read uses the cookie sent with that request.

In an Atom Starter app, add these imports to `app/Application.php`.

```php
use Atom\Di\Injector;
use Atom\Http\Cookie;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Router\Route;
```

Register the three routes in `bootstrap()`. If the method already exists, add the routes to its body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::get("/reader/theme", static function (Request $request, Response $response): Response {
        return $response->json(["theme" => $request->cookies()->string("reader.theme", "system")]);
    });

    Route::post("/reader/theme/dark", static function (Response $response): Response {
        $theme = Cookie::create("reader.theme", "dark")->withPath("/reader")->expiresAfter(7 * 86400);

        return $response->cookie($theme)->content("Dark theme saved.");
    });

    Route::delete("/reader/theme", static function (Response $response): Response {
        return $response->removeCookie("reader.theme", path: "/reader")->content("Theme reset.");
    });
}
```

Run `php atom serve` from the app root. In a second PowerShell terminal, use one client session so each request carries the cookies from earlier responses.

```powershell
$url = "http://127.0.0.1:8020/reader/theme"
(Invoke-RestMethod $url -SessionVariable reader).theme
Invoke-RestMethod "$url/dark" -Method Post -WebSession $reader
(Invoke-RestMethod $url -WebSession $reader).theme
Invoke-RestMethod $url -Method Delete -WebSession $reader
(Invoke-RestMethod $url -WebSession $reader).theme
```

The three reads return `system`, `dark`, and `system`. The POST sends a `Set-Cookie` header scoped to `/reader`. The DELETE expires the same cookie at the same path, so the final GET uses the fallback.

## Cookies from Services

### Save a preference before redirecting

After saving a preference, send the reader back to the theme endpoint. The service queues the cookie and the route chooses the redirect. Use the three routes from [Remember a reader's theme](#remember-a-readers-theme).

Create `app/Services/ReaderPreferences.php` in your Starter app.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Atom\Http\Cookie;
use Atom\Http\CookieJar;

final readonly class ReaderPreferences
{
    public function __construct(private CookieJar $cookies)
    {
    }

    public function useDarkTheme(): void
    {
        $this->cookies->set(
            Cookie::create("reader.theme", "dark")->withPath("/reader")->expiresAfter(7 * 86400)
        );
    }
}
```

Import `App\Services\ReaderPreferences` in `app/Application.php` and replace the `POST /reader/theme/dark` registration inside `bootstrap()` with this route.

```php
Route::post("/reader/theme/dark", static function (ReaderPreferences $preferences): Response {
    $preferences->useDarkTheme();
    return (new Response())->redirect("/reader/theme", 303);
});
```

With the development server running, use a temporary cookie file to inspect each response in PowerShell.

```powershell
$url = "http://127.0.0.1:8020/reader/theme"
$cookieFile = [System.IO.Path]::GetTempFileName()
try {
    curl.exe -sS -i -X POST -c $cookieFile "$url/dark"
    curl.exe -sS -b $cookieFile $url
} finally {
    Remove-Item -LiteralPath $cookieFile
}
```

The POST returns `303 See Other` with both `Location: /reader/theme` and a `Set-Cookie` header for `reader.theme=dark`. Atom applies the request-scoped jar to the response the route returns. The second command sends the saved cookie to the redirect destination and receives `{"theme":"dark"}`.

Cookies set on an injected `Response` also use the request-scoped jar. Inject `CookieJar` directly when the service only needs to set or remove a cookie.

## Sessions

`NativeSession` uses the outgoing jar for the session cookie and disables PHP's automatic cookie and cache-limiter headers. This keeps `Application::handle()` from emitting headers and ensures the session cookie is attached to the final Atom response.
