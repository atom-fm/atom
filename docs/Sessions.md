# Sessions

[Atom Framework](Index.md)

Sessions are core request infrastructure under `Atom\Session`. They are not a feature module and do not need to be registered by applications.

Atom binds `SessionInterface` to the native PHP session store by default. The store is request-scoped and lazy: constructing or injecting it does not start a PHP session. The session starts on the first read or write and is saved and closed automatically when `Application::handle()` finishes.

## Using Sessions

Inject `SessionInterface` into a page, controller, route action, or application service:

```php
use Atom\Session\SessionInterface;

final class AccountService
{
    public function __construct(private SessionInterface $session)
    {
    }

    public function rememberUser(int $userId): void
    {
        $this->session->put("user_id", $userId);
    }
}
```

The core operations are:

```php
$session->put("user_id", 42);
$session->get("user_id");
$session->get("missing", "default");
$session->has("user_id");
$session->pull("one_time_value");
$session->remove("user_id");
$session->all();
$session->clear();
```

Stored `null` values are distinct from missing keys when using `has()`.

Regenerate the session ID after authentication or another privilege change:

```php
$session->regenerate(deleteOldSession: true);
```

Invalidate the complete session during logout:

```php
$session->invalidate();
```

The current application also exposes `getSession()` for infrastructure code that already has the `Application` instance. Constructor injection is preferred for ordinary services.

## Reading Request Cookies

`Request::cookies()` exposes the parsed, read-only `CookieCollection`:

```php
$theme = $request->cookies()->string("theme", "system");

if ($request->cookies()->has("consent")) {
    // ...
}
```

Cookie names are preserved exactly, including names containing dots. Values use percent decoding without converting literal `+` characters into spaces. Raw access remains available through `$request->headers()->get("Cookie")` when needed.

## Flash Data

### Show a confirmation once after a redirect

Use a regular session value for a preference that should survive a refresh, and a flash value for the confirmation shown immediately after saving. In this example a POST enables compact view, then redirects to a GET that reports the saved view and any notice.

In a local Atom Starter project, add these imports to `app/Application.php`.

```php
use Atom\Di\Injector;
use Atom\Http\Response;
use Atom\Router\Route;
use Atom\Session\FlashBag;
use Atom\Session\SessionInterface;
```

Add this method to `Application`. If it already has a `bootstrap()` method, add the two route registrations inside it.

```php
protected function bootstrap(Injector $injector): void
{
    Route::post("/preferences/compact", static function (
        SessionInterface $session,
        FlashBag $flash,
        Response $response
    ): Response {
        $session->put("view", "compact");
        $flash->put("notice", "Compact view enabled.");
        return $response->redirect("/preferences", 303);
    });

    Route::get("/preferences", static function (SessionInterface $session, FlashBag $flash): array {
        return [
            "view" => $session->get("view", "comfortable"),
            "notice" => $flash->pull("notice"),
        ];
    });
}
```

Run `php atom serve` from the project root. In a second PowerShell window, use the server's default address to save the preference. PowerShell keeps the session cookie in `$prefs` so the next request can reuse it.

```powershell
$base = "http://127.0.0.1:8020"
Invoke-RestMethod -Method Post -Uri "$base/preferences/compact" -SessionVariable prefs | ConvertTo-Json -Compress
```

The POST returns `303 See Other`. PowerShell follows it with a GET and prints the confirmation.

```json
{"view":"compact","notice":"Compact view enabled."}
```

Repeat just the GET using the saved cookie.

```powershell
Invoke-RestMethod -Uri "$base/preferences" -WebSession $prefs | ConvertTo-Json -Compress
```

```json
{"view":"compact","notice":null}
```

`pull()` reads and removes the notice. The separate `view` value remains in the session. To retain a current flash value for one additional request, call `keep("notice")` before it expires and use `get("notice")` to read it. `reflash()` retains all current flash values. `Page::flash()` is a separate helper for displaying a message on the current page.

### Read a saved view on a first or return visit

A public catalogue can show the comfortable layout on a first visit and use compact mode for readers who saved that preference. Calling `$session->get()` starts the native session even when the key is missing. Check for the configured session cookie before reading the preference.

Keep the routes from [Show a confirmation once after a redirect](#show-a-confirmation-once-after-a-redirect). Add `use Atom\Http\Request;` and `use Atom\Session\SessionOptions;` to `app/Application.php` and register this route in the same `bootstrap()` method.

```php
Route::get("/catalogue", static function (
    Request $request,
    SessionInterface $session,
    SessionOptions $options
): array {
    $view = "comfortable";
    if ($request->cookies()->has($options->name)) {
        $view = $session->get("view", $view);
    }

    return ["view" => $view];
});
```

With the Starter server running, compare a fresh visit with a visit after saving the compact preference.

```powershell
$base = "http://127.0.0.1:8020"
curl.exe -sS -i "$base/catalogue"
Invoke-RestMethod -Method Post -Uri "$base/preferences/compact" -SessionVariable reader | Out-Null
Invoke-RestMethod -Uri "$base/catalogue" -WebSession $reader | ConvertTo-Json -Compress
```

The first GET returns `{"view":"comfortable"}` without issuing a session cookie. The POST stores the preference, and the final GET returns `{"view":"compact"}`. Using `$options->name` also makes the lookup follow a custom `SESSION_NAME`.

## Configuration

Native sessions use these environment options:

```env
SESSION_NAME=ATOMSESSID
SESSION_LIFETIME=0
SESSION_IDLE_TIMEOUT=0
SESSION_PATH=/
SESSION_DOMAIN=
SESSION_SECURE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=Lax
SESSION_STRICT_MODE=true
```

When `SESSION_SECURE` is omitted, Atom enables secure cookies automatically for HTTPS requests. `SESSION_SAME_SITE` accepts `Lax`, `Strict`, or `None`.

`SESSION_LIFETIME` controls how long the browser keeps the session cookie. A value of `0` makes it a browser-session cookie. PHP and its session handler manage cleanup of stored session records.

`SESSION_IDLE_TIMEOUT` sets the server-side idle limit in seconds. The default of `0` disables it. For example, set `SESSION_IDLE_TIMEOUT=1800` for a 30-minute limit. Activity is recorded when a request opens the session. Requests that leave the session unopened leave its activity time as is. At or beyond the limit, Atom clears the session data and replaces its ID before authentication can read the old identity. A protected route that uses `AuthenticateMiddleware` then returns `401`. Sessions created before the setting was enabled start tracking idle time on their first session access.

## Tests

### Test a notice across two redirects

After checkout, an order-status page redirects the customer to a pickup or delivery receipt. That second redirect must carry the checkout confirmation forward once. Use `ArraySession` and `FlashBag` to test that sequence locally. Reuse one session object and call `save()` at each request boundary. The next access starts the next simulated request and ages its flash data.

Save `check-receipt-notice.php` beside `composer.json` in an installed Atom Starter project.

```php
<?php

declare(strict_types=1);

use Atom\Session\ArraySession;
use Atom\Session\FlashBag;

require __DIR__ . "/vendor/autoload.php";

$session = new ArraySession();
$flash = new FlashBag($session);

// The order submission redirects to the order-status page.
$flash->put("receipt", "Order 42 saved.");
$flash->put("hint", "Locating your receipt.");
$session->save();

// The order-status page redirects to the receipt. Keep only the receipt confirmation.
$flash->keep("receipt");
$session->save();

// The receipt page displays the notice. A refresh starts another request.
if ($flash->all() !== ["receipt" => "Order 42 saved."]) {
    throw new RuntimeException("Expected only the receipt confirmation.");
}
echo $flash->get("receipt"), PHP_EOL;
$session->save();

if ($flash->has("receipt")) {
    throw new RuntimeException("The receipt confirmation survived a refresh.");
}
echo "No notice on refresh.", PHP_EOL;
```

Run `php check-receipt-notice.php` from the project root. A passing run prints these lines and exits with code `0`.

```text
Order 42 saved.
No notice on refresh.
```

The receipt page reads with `get()`, leaving expiration to the session lifecycle. To see why the intermediate page calls `keep()`, temporarily remove `keep("receipt")` and rerun. The first check fails because the confirmation expires before the receipt page reads it.

Applications can replace the `SessionInterface` binding from an application service provider. Keep the replacement request-scoped so it follows the normal request lifecycle.
