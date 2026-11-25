# API Explorer

[Atom Framework](Index.md)

Use API Explorer to inspect the fields an endpoint accepts and send a request to its handler. This example looks up an article twice so you can compare a successful response with a missing record.

## Mount the explorer

Start with the article controller from the [Routing walkthrough](Router.md#route-requests-to-a-controller) in a local Atom Starter project. Use its original `/api/articles` route. The controller includes one article with the slug `building-atom`, so this example needs no database.

In `app/Application.php`, add this import.

```php
use Atom\Modules\ApiExplorer\ApiExplorer;
```

Add the following line inside the existing `modules()` method, alongside the starter's module registrations.

```php
$modules->add(ApiExplorer::module(), "/atom/api");
```

Run `php atom serve` from the project root and open `/atom/api` at the address it prints. Atom redirects to `/atom/api/explorer`. The endpoint list includes routes marked with `#[ApiController]` or `->api()` at any path. Pass `apiPathPrefix: "/v1/api"` to `ApiExplorer::module()` to narrow the list to marked endpoints under that prefix. Routes carrying `ApiHidden` metadata are excluded.

## Send an article lookup

1. Under **Endpoints**, select **GET** `/api/articles/{slug}`. The **Request shape** table lists `slug` as a required string with location `route`.
2. In **Request URL**, enter `/api/articles/building-atom` and click **Send**. The **Response** panel shows `HTTP 200 OK` and the article.
3. Change the URL to `/api/articles/missing` and click **Send** again. The same handler now returns `HTTP 404 Not Found` with the message `Article not found`.

For the successful lookup, the JSON body contains the stored title and publication flag.

```json
{"title": "Building Atom", "published": true}
```

**Send** runs the selected request through the application's dispatcher. The status and body in the panel come from the handler.

## Show the article's 404 response in the explorer

A client developer can look up an error's status and JSON fields before calling the endpoint. Use one response class for the article handler's missing-record branch and its explorer documentation.

Create `app/Api/Responses/ArticleNotFound.php` in the Starter app.

```php
<?php

declare(strict_types=1);

namespace App\Api\Responses;

final readonly class ArticleNotFound
{
    public function __construct(public string $error = "Article not found")
    {
    }
}
```

In `app/Controllers/ArticleController.php`, import the response class and the explorer attribute.

```php
use App\Api\Responses\ArticleNotFound;
use Atom\Api\Attributes\ErrorResponse;
```

Add this attribute alongside the existing route attributes on `show()`.

```php
#[ErrorResponse(404, ArticleNotFound::class, "No article matches the requested slug.")]
```

Inside the existing `if ($article === null)` branch, replace the return statement with this line. The handler sets the HTTP status and serializes the public `error` property as JSON.

```php
return $response->status(404)->json(new ArticleNotFound());
```

Reload the explorer and select **GET** `/api/articles/{slug}`. Under **Error responses**, look for `404`, `ArticleNotFound`, the description, and the `error` field of type `string`. Enter `/api/articles/missing` in **Request URL** and click **Send** to compare the documented response with the actual HTTP `404` body.

```json
{"error":"Article not found"}
```

## Describe routes in PHP

To check which article routes a Starter app actually registered, list their HTTP methods and paths from the command line. Use the article controller from [Mount the explorer](#mount-the-explorer) and save `list-api.php` beside `composer.json`.

```php
<?php

declare(strict_types=1);

use App\Application;
use Atom\Api\ApiModelBuilder;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();

$prefix = $argv[1] ?? "/api/articles";
$description = (new ApiModelBuilder())->describe($app->getRouter(), $prefix);
if ($description->endpoints === []) {
    echo "No endpoints under {$prefix}.", PHP_EOL;
}

foreach ($description->endpoints as $endpoint) {
    foreach ($endpoint->methods as $method) {
        echo $method, " ", $endpoint->path, PHP_EOL;
    }
}
```

Run `php list-api.php` from the project root. With the original controller mount, the list includes these three method and path pairs.

```text
GET /api/articles/{slug}
HEAD /api/articles/{slug}
POST /api/articles/preview
```

Run `php list-api.php /api` to include the walkthrough's health check. If you moved the controller under `/v1`, the original prefix yields `No endpoints under /api/articles.` Run `php list-api.php /v1/api/articles` to see its mounted routes. The script reads the router's metadata without sending a request to any endpoint.

Use `ResponseOf` with wrapper response models when the action returns a container such as a paged response:

```php
use Atom\Api\Attributes\ArrayOf;
use Atom\Api\Attributes\ResponseOf;

#[ResponseOf(ArticleResponse::class)]
public function articles(): PagedResponse
{
    return new PagedResponse();
}

final class PagedResponse
{
    #[ArrayOf]
    public array $items = [];

    public int $total;
    public int $page;
}
```

Use `#[ArrayOf(SomeResponse::class)]` when the array item type is concrete and does not depend on the action response.

`ApiDescription` contains endpoint descriptors:

```php
$description->endpoints[0]->methods;
$description->endpoints[0]->path;
$description->endpoints[0]->handler;
$description->endpoints[0]->requestFields;
$description->endpoints[0]->responseType;
```

## Request Fields

Action scalar parameters become fields. Route parameters are inferred from paths such as:

```text
/api/users/{id}
```

DTO parameters marked with `#[Dto]` are expanded into property fields:

```php
use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Attributes\FromQuery;
use Atom\Validation\Rules\Required;

#[Dto]
final class CreateUserDto
{
    #[FromBody]
    #[Required]
    public string $name;

    #[FromBody]
    public int $age;

    #[FromQuery("invite")]
    public ?string $inviteCode = null;
}
```

Each field describes:

- source: `auto`, `body`, `query`, `route`, `header`, or `file`
- source name
- PHP type
- whether the field is required
- validation rule classes
- DTO model class, when the field came from a DTO

The explorer renders these descriptors in the selected endpoint's request table.
