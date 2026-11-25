# Hydration

Atom hydrates request DTOs through `RequestHydrator`. DTOs may use writable properties or constructor parameters, including promoted readonly properties.

## Constructor DTOs

Build a badge preview for an event. A single request DTO takes the event number from the URL and the attendee name and copy count from a submitted form. In an Atom Starter app, create `app/Requests/BadgeRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Attributes\FromRoute;

#[Dto]
final readonly class BadgeRequest
{
    public function __construct(
        #[FromRoute] public int $eventId,
        #[FromBody("full_name")] public string $name,
        #[FromBody] public int $copies = 1,
    ) {
    }
}
```

Add these imports to `app/Application.php`.

```php
use App\Requests\BadgeRequest;
use Atom\Di\Injector;
use Atom\Http\Response;
use Atom\Router\Route;
```

Register the route in `bootstrap()`. If your application already has this method, add the route inside it.

```php
protected function bootstrap(Injector $injector): void
{
    Route::post("/events/{eventId}/badge-preview", static function (BadgeRequest $badge, Response $response): Response {
        if ($badge->copies < 1 || $badge->copies > 10) {
            return $response->status(422)->json(["error" => "Choose between 1 and 10 copies."]);
        }

        return $response->json([
            "event" => $badge->eventId,
            "label" => $badge->name,
            "copies" => $badge->copies,
        ]);
    });
}
```

Run `php atom serve` in the app root. In a second PowerShell terminal, preview a badge for event 42.

```powershell
curl.exe --data-urlencode "full_name=Ada Novak" http://127.0.0.1:8020/events/42/badge-preview
```

```json
{"event":42,"label":"Ada Novak","copies":1}
```

The route placeholder `{eventId}` supplies the integer `42`. The `FromBody("full_name")` attribute maps the form field to `$name`. Omitting `copies` uses the constructor default. Add `--data-urlencode "copies=2"` to the command to receive `"copies":2`. Sending `copies=0` reaches the action as an integer and triggers its `422` response.

Constructor parameters support `FromBody`, `FromQuery`, `FromRoute`, `FromHeader`, `FromFile`, `Raw`, and value-transformer attributes such as `DateFormat`. Missing values use declared constructor defaults. An explicitly supplied `null` remains `null` when the type permits it.

## Coercion

The hydrator supports strings, integers, floats, booleans, arrays, mutable and immutable dates, backed enums, unit enums, and nested typed DTOs represented by input arrays. Invalid or missing required values throw `HydrationException` with the class and target name.

When no explicit source attribute is present, lookup checks body, query, and route data in that order. Constructor hydration runs first; remaining writable non-promoted properties are hydrated afterward.

If an injected request DTO has a missing or invalid body field, Atom returns a 422 JSON response. The error uses request field names, including aliases and nested paths such as `shipping_address.postal_code`.

Hydration performs type construction and coercion. Business validation remains explicit through `Validator`, allowing applications to decide when and how validation errors become responses.

### Check badge inputs from the command line

When a badge preview returns an unexpected copy count, you can pass the form and route values directly to `RequestHydrator`. Use the `BadgeRequest` class from [Constructor DTOs](#constructor-dtos) and save `check-badge.php` in the Starter project root.

```php
<?php

declare(strict_types=1);

use App\Requests\BadgeRequest;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Hydrator\HydrationContext;
use Atom\Hydrator\RequestHydrator;

require __DIR__ . "/vendor/autoload.php";

$body = ["full_name" => "  Ada Novak  "];
if (isset($argv[1])) {
    $body["copies"] = $argv[1];
}

try {
    $badge = (new RequestHydrator())->hydrate(
        BadgeRequest::class,
        new HydrationContext(body: $body, route: ["eventId" => "42"])
    );
    echo json_encode($badge, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (HydrationException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
```

Run the script from the project root.

- `php check-badge.php` prints `{"eventId":42,"name":"Ada Novak","copies":1}`. The name is trimmed and the omitted field uses the constructor default.
- `php check-badge.php 2` returns `copies` as the JSON number `2`.
- `php check-badge.php two` exits with status `1` and reports that `BadgeRequest::copies` expected an `int`.
- `php check-badge.php 0` succeeds with `copies` set to `0`. The badge route applies its range check afterward and returns HTTP `422` for this value.
