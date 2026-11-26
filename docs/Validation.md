# Validation

[Atom Framework](Index.md)

Validation supports two styles: fluent schemas for manual validation and attributes for DTO validation.

## Fluent Schema

Build a preview endpoint for a ticket form. It accepts a subject of up to 80 characters and a priority of `normal` or `urgent`. The caller can use the field names in the response to display errors beside the corresponding inputs.

In an Atom Starter app, add these imports to `app/Application.php`.

```php
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Router\Route;
use Atom\Validation\Schema;
```

Register the endpoint in the application's `bootstrap()` method. If the method already exists, add the route to its body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::post("/api/tickets/preview", static function (Request $request, Response $response): Response {
        $fields = [
            "subject" => trim($request->post()->string("subject")),
            "priority" => $request->post()->string("priority"),
        ];

        $schema = Schema::make()
            ->field("subject")->required("Enter a subject.")->maxLength(80, "Use 80 characters or fewer.")
            ->field("priority")->required("Choose a priority.")->in(["normal", "urgent"], "Choose normal or urgent.")
            ->schema();

        $result = $schema->validate($fields);
        if ($result->failed()) {
            return $response->status(422)->json(["errors" => $result->messages()]);
        }

        return $response->json(["preview" => $fields]);
    })->api();
}
```

The `required()` rule catches a missing priority. The `in()` rule checks a supplied choice. `messages()` groups all failures by field, so a single submission can tell the caller about both inputs.

### Submit the form

Run `php atom serve` from the app root. In a second PowerShell terminal, submit an empty subject and an unrecognized priority.

```powershell
curl.exe -i --data-urlencode "subject=" --data-urlencode "priority=high" http://127.0.0.1:8020/api/tickets/preview
```

The response has HTTP status `422` and this JSON body.

```json
{"errors":{"subject":["Enter a subject."],"priority":["Choose normal or urgent."]}}
```

Correct both fields and submit again.

```powershell
curl.exe -i --data-urlencode "subject=Fix checkout" --data-urlencode "priority=urgent" http://127.0.0.1:8020/api/tickets/preview
```

The endpoint returns the preview with HTTP status `200`.

```json
{"preview":{"subject":"Fix checkout","priority":"urgent"}}
```

### Require a reply address for urgent tickets

A normal ticket can omit a reply address. An urgent ticket needs one so support can follow up. Reuse the [ticket preview endpoint](#fluent-schema) and its existing priority rule.

Inside the route, immediately before `$result = $schema->validate($fields);`, add the submitted address and its rules.

```php
$fields["reply_email"] = trim($request->post()->string("reply_email"));
$replyEmail = $schema->field("reply_email")->email("Enter a valid reply address.");
if ($fields["priority"] === "urgent") {
    $replyEmail->required("Add a reply address for urgent tickets.");
}
```

`email()` checks the format of a supplied value and accepts an empty field. Adding `required()` to the same field for an urgent request gives a missing address its own message. Both messages use the `reply_email` key in the existing error response.

With the development server running, send the same ticket at each priority.

```powershell
$url = "http://127.0.0.1:8020/api/tickets/preview"
curl.exe -i --data-urlencode "subject=Printer offline" --data-urlencode "priority=normal" $url
curl.exe -i --data-urlencode "subject=Printer offline" --data-urlencode "priority=urgent" $url
```

The normal ticket returns HTTP `200`. The urgent ticket returns HTTP `422` with this body.

```json
{"errors":{"reply_email":["Add a reply address for urgent tickets."]}}
```

Add `--data-urlencode "reply_email=desk@example.test"` to the urgent request to receive a `200` preview. Submitting `reply_email=desk` at either priority returns `422` with `Enter a valid reply address.`

## Attribute Validation

### Check an article draft before publication

Put the field rules on the request object when several actions need to check the same article format. In an Atom Starter app, create `app/Requests/CreateArticleDto.php`. The title and body start empty so a missing field reaches the `Required` rule. Omitting the status selects `draft`.

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Validation\Rules\In;
use Atom\Validation\Rules\MaxLength;
use Atom\Validation\Rules\Required;

#[Dto]
final class CreateArticleDto
{
    #[FromBody]
    #[Required("Enter a title.")]
    #[MaxLength(120, "Use 120 characters or fewer.")]
    public string $title = "";

    #[FromBody]
    #[Required("Enter the article text.")]
    public string $body = "";

    #[FromBody]
    #[Required("Choose a status.")]
    #[In(["draft", "published"], "Choose draft or published.")]
    public string $status = "draft";
}
```

In `app/Application.php`, import `App\Requests\CreateArticleDto` and `Atom\Validation\Validator` alongside the `Route` and `Response` imports from the ticket example. Add this route inside `bootstrap()`.

```php
Route::post("/api/articles/check", static function (CreateArticleDto $article, Response $response): Response {
    $result = Validator::for()->validate($article);
    if ($result->failed()) {
        return $response->status(422)->json(["errors" => $result->messages()]);
    }

    return $response->json(["title" => $article->title, "status" => $article->status]);
})->api();
```

Atom fills the DTO from the form submission. The action calls `validate()` to apply the property rules and `messages()` to group failures by field. With `php atom serve` running, submit a body and an unsupported status.

```powershell
curl.exe -i --data-urlencode "body=Doors open at noon." --data-urlencode "status=queued" http://127.0.0.1:8020/api/articles/check
```

The response is HTTP `422` and reports both fields that need attention.

```json
{"errors":{"title":["Enter a title."],"status":["Choose draft or published."]}}
```

Supply a title and omit the status to use the default.

```powershell
curl.exe -i --data-urlencode "title=Weekend opening" --data-urlencode "body=Doors open at noon." http://127.0.0.1:8020/api/articles/check
```

The response is HTTP `200` with `{"title":"Weekend opening","status":"draft"}`. The endpoint checks the draft without saving it.

## Nested objects

Add `#[ValidateNested]` to each object property whose values should be checked. Import `Atom\Validation\ValidateNested` and put the validation rules on the nested class.

```php
final class ShippingAddress
{
    #[Required("Enter a postal code.")]
    public string $postalCode = "";
}

#[Dto]
final readonly class OrderInput
{
    public function __construct(
        #[ValidateNested]
        public ShippingAddress $address
    ) {
    }
}
```

When a DTO action calls `Validator::for()->validate($input)`, `messages()` returns errors under dotted PHP property names such as `address.postalCode`, including when hydration uses a different input name. Use the same HTTP `422` response pattern as above to send those errors to the caller.

Page forms use the same attribute on their model properties. Call `$this->validate()` in the form action before saving. A field named `address[postalCode]` displays the error for `address.postalCode`.

Each level of nesting requires its own `#[ValidateNested]`. A null or uninitialized nested property is skipped. Add `#[Required]` to that property if an object must be present.

The Showcase order form at `/examples/order-form` demonstrates errors at `address.postalCode` and `address.unit.number`. After saving an order, the update form submits only `address[unit][number]` and keeps the other fields from the saved order.

