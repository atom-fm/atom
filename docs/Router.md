# Routing

An Atom route pairs an HTTP method and a URL with a PHP handler. Start with a health check, then add a controller that looks up articles by slug and previews a submitted title.

For standalone matching, use a `RouteMatcher` with a `Router`. A successful match contains the route definition and request parameters. In a Framework application, Dispatcher runs middleware and invokes the matched handler with hydrated arguments and injected dependencies.

## Generate URLs from route names

Name a route with `->name()` or with the `name` argument on a controller route attribute or `#[PageRoute]`. Once the route is registered, `Route::url()` returns its site-relative URL as a string. It includes any group or module prefixes.

```php
$url = Route::url(
    "articles.show",
    ["slug" => "building-atom"],
    query: ["view" => "full"],
);
// /api/articles/building-atom?view=full
```

Pass path placeholders in the second argument and query values separately. Atom URL-encodes path values and preserves the slash between segments of a wildcard value such as `images/photo.jpg`. Unknown route names, missing or extra path parameters, and names shared by different paths throw `InvalidArgumentException`.

## Return JSON from a route

In `app/Application.php`, import `Atom\Di\Injector` and `Atom\Router\Route`. Add this method to your application class, or add the registration line to its existing `bootstrap()` method.

```php
protected function bootstrap(Injector $injector): void
{
    Route::get("/api/health", static fn(): array => ["status" => "ok"])->api();
}
```

Start your app with `php atom serve`. With its default address, you can call the endpoint from a second terminal.

```powershell
curl.exe -i http://127.0.0.1:8020/api/health
```

Atom serializes the returned array as JSON. The response has status `200`, content type `application/json`, and this body.

```json
{"status":"ok"}
```

## Route requests to a controller

Create `app/Controllers/ArticleController.php`. The controller supplies the shared `/api/articles` prefix. Each method attribute supplies the remaining path and the HTTP verb.

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Hydrator\Attributes\FromRoute;
use Atom\Router\Attributes\ApiController;
use Atom\Router\Attributes\Get;
use Atom\Router\Attributes\Head;
use Atom\Router\Attributes\Post;

#[ApiController("/api/articles")]
final class ArticleController
{
    private const ARTICLES = [
        "building-atom" => ["title" => "Building Atom", "published" => true],
    ];

    #[Get("{slug}", name: "articles.show", title: "Read an article")]
    #[Head("{slug}")]
    public function show(#[FromRoute] string $slug, Response $response): Response
    {
        $article = self::ARTICLES[$slug] ?? null;
        if ($article === null) {
            return $response->status(404)->json(["error" => "Article not found"]);
        }

        return $response->json($article);
    }

    #[Post("preview")]
    public function preview(Request $request, Response $response): Response
    {
        $title = trim($request->post()->string("title"));
        if ($title === "") {
            return $response->status(422)->json(["error" => "Enter a title"]);
        }

        return $response->json(["title" => $title, "published" => false]);
    }
}
```

Import `App\Controllers\ArticleController` in `app/Application.php`. Add this line inside the same `bootstrap()` method as the health check.

```php
Route::attach(ArticleController::class);
```

Use `#[Controller]` for a general HTTP controller and `#[ApiController]` to include its attributed routes in API discovery. Both accept a path and optional `middleware`. Each HTTP verb attribute and `#[HttpRoute]` also accepts `name`, `middleware`, `title`, and `description`. Middleware runs from the enclosing group through the controller to the individual route. For a route registered directly, chain `->api()` as in the health check.

## Read an article

The `{slug}` segment captures one part of the path. `#[FromRoute]` passes that value to `show()`.

```powershell
curl.exe -i http://127.0.0.1:8020/api/articles/building-atom
```

The example returns `200` with the article from its in-memory collection.

```json
{"title":"Building Atom","published":true}
```

Change `building-atom` to `missing`. The same route still matches, and the controller returns `404` with `{"error":"Article not found"}`.

## Check an article link with HEAD

A link checker needs the status of an article URL without downloading its JSON body. In the controller above, `#[Head("{slug}")]` registers `HEAD` alongside `GET` on `show()`. Both methods use the same slug lookup, so a removed article returns the same status for either request.

Run these commands against the Starter development server.

```powershell
curl.exe --head http://127.0.0.1:8020/api/articles/building-atom
curl.exe --head http://127.0.0.1:8020/api/articles/missing
```

The first response is HTTP `200` and the second is HTTP `404`. Both return the `application/json` content type and no response body. Use the `GET` request above when you also need the title or the error message.

## Submit a preview

Send `title` as a form field. The `#[Post("preview")]` route calls `preview()`, which returns the draft without saving it.

```powershell
curl.exe -i --data-urlencode "title=My first article" http://127.0.0.1:8020/api/articles/preview
```

The response is `200` with this JSON body.

```json
{"title":"My first article","published":false}
```

Try `--data-urlencode "title="` to see the `422` validation response. A `POST` to `/api/health`, which only accepts `GET`, returns `405 Method Not Allowed`.

## Diagnose a 405 when submitting a preview

If a preview form posts to the article's read URL, Atom returns `405 Method Not Allowed` before either controller method runs. Reproduce the form submission against the [article controller](#route-requests-to-a-controller). Use `-i` to see the response headers.

```powershell
curl.exe -i -H "Accept: application/json" --data-urlencode "title=Revised edition" http://127.0.0.1:8020/api/articles/building-atom
```

Look for `405` on the status line and `GET` and `HEAD` in the `Allow` header. Those methods belong to `show()` on the matching `{slug}` path. The header describes this URL. The controller accepts a preview submission at `POST /api/articles/preview`.

Keep the form data and change the destination.

```powershell
curl.exe -i -H "Accept: application/json" --data-urlencode "title=Revised edition" http://127.0.0.1:8020/api/articles/preview
```

The corrected request returns `200` with `{"title":"Revised edition","published":false}`. Use that URL as the form's `action` and `post` as its `method`.

## Mount the same API under a version prefix

To serve the article endpoints under `/v1`, use `attachTo()` in place of `attach()`.

```php
Route::attachTo("/v1", ArticleController::class);
```

The lookup is now `GET /v1/api/articles/building-atom`. The separately registered health check stays at `GET /api/health`.

See [Middleware](Middlewares.md) to run code around a request and [API Explorer](ApiExplorer.md) to inspect registered endpoints.
