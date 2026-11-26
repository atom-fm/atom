# Pages and View Engine

[Atom Framework](Index.md)

Pages are the preferred way to build browser-facing screens.

A page is a PHP class plus an adjacent `.atom.html` template:

```text
app/Pages/
├── HomePage.php
└── HomePage.atom.html
```

## Register Pages

Register page directories from the application `pages()` hook:

```php
use Atom\Page\PageRegistry;

protected function pages(PageRegistry $pages): void
{
    $pages->directory("@app/Pages");
}
```

You can also mount a page directory under a route prefix:

```php
$pages->directory("@app/AdminPages", "/admin");
```

Middleware shared by every page in a directory can be provided during registration:

```php
$pages->directory("@app/AdminPages", "/admin", [
    AuthenticateMiddleware::class,
    CsrfMiddleware::class,
]);
```

Directory middleware runs before middleware declared by an individual `PageRoute`. Modules can use the equivalent `ModuleContext` API:

```php
$context->pages(__DIR__ . "/Pages", [
    AccountsPageMiddleware::class,
    CsrfMiddleware::class,
]);
```

`Page::registerPages()` is still available for manual registration.

Page templates and `TemplateComponent` `.atom.html` templates are parsed into an AST and cached in memory by template path and modified time. Rendering stays dynamic; only the parsed template structure is reused.

## Page Class

```php
namespace App\Pages;

use Atom\Page\PageRoute;

#[PageRoute(
    "/",
    name: "home",
    title: "Home",
    description: "Application home page."
)]
final class HomePage extends AppPage
{
    public string $title = "Atom Sample";

    public function get(): void
    {
        // Load page state before rendering.
    }
}
```

Route middleware can be declared on the same attribute. It applies to the page render route and every HTTP method discovered from its page actions:

```php
#[PageRoute(
    "/account",
    middleware: [AccountsPageMiddleware::class, CsrfMiddleware::class],
    title: "Account",
    description: "Manage the current account."
)]
```

The title and description are copied to every generated route entry, making them available to API exploration and other route metadata consumers without a separate registration loop.

The method matching the request method is invoked before rendering:

```php
public function get(): void {}
public function post(Request $request, Response $response): Response {}
public function put(): mixed {}
public function delete(): mixed {}
```

If the method returns a non-null result, that result is used directly.
If it returns `null` or `void`, the page template is rendered.

Route parameters are passed into the page method by name:

```php
#[PageRoute("/articles/{id}")]
final class ArticleDetailsPage extends AppPage
{
    public function get(string $id): void
    {
    }
}
```

## Page Templates

`HomePage.atom.html`:

```html
<section class="hero">
    <h1>{{ $this->title }}</h1>
</section>
```

The page instance is available as both:

```php
$this
$page
```

Expressions are escaped by default:

```html
{{ $article->title }}
```

Attributes support interpolation:

```html
<a href="/articles/{{ $article->id }}">{{ $article->title }}</a>
```

A bound attribute such as `:title="$article->title"` evaluates its expression once. Any `{{ ... }}` in the resulting string stays literal.

Attribute values are HTML-escaped, including `HtmlString` values. In element content, `{{ ... }}` renders an `HtmlString` as markup.

HTML entities in literal attribute text are decoded before output is escaped. Use `value=""` to render an empty value and bind `null` or `false` to omit an attribute.

## Directives

### Filter a workshop list with a GET form

Give readers a URL they can bookmark or share with their search already applied. The page reads the query, and its template uses `@foreach` to display matches and `@if` to show an empty result.

In an Atom Starter project with Composer dependencies installed, create `app/Pages/WorkshopsPage.php`. The starter already discovers pages in this directory.

```php
<?php

declare(strict_types=1);

namespace App\Pages;

use Atom\Http\Request;
use Atom\Page\PageRoute;

#[PageRoute("/workshops")]
final class WorkshopsPage extends AppPage
{
    private const WORKSHOPS = ["Intro to PHP", "Testing PHP applications", "Designing a database"];

    public string $title = "Workshops";
    public string $search = "";

    /** @var list<string> */
    public array $matches = [];

    public function get(Request $request): void
    {
        $this->search = trim($request->query()->string("q"));
        foreach (self::WORKSHOPS as $workshop) {
            if ($this->search === "" || stripos($workshop, $this->search) !== false) {
                $this->matches[] = $workshop;
            }
        }
    }
}
```

Save the template beside the class as `app/Pages/WorkshopsPage.atom.html`. The field name `q` matches the key read by `get()`. Its `value` shows the search term again after submission.

```html
<h1>{{ $this->title }}</h1>
<form method="get" action="/workshops" atom:navigate atom:preserve-scroll>
    <label for="workshop-search">Find a workshop</label>
    <input id="workshop-search" type="search" name="q" value="{{ $this->search }}">
    <button type="submit">Search</button>
</form>

@if($this->matches === [])
    <p>No workshops match your search.</p>
@else
    <ul>
        @foreach($this->matches as $workshop)
            <li>{{ $workshop }}</li>
        @endforeach
    </ul>
@endif
```

Run `php atom serve` from the project root and open `/workshops` on the address it prints. All three workshops appear. Search for `php` to see the two PHP workshops at `/workshops?q=php`. Reloading that URL shows the same list. Searching for `gardening` shows the empty-result message, and submitting an empty field restores all three workshops.

## Layouts

Pages can specify a layout component class.

```php
namespace App\Pages;

use App\Components\Layout;
use Atom\Page\Page;

abstract class AppPage extends Page
{
    public ?string $layout = Layout::class;

    public string $title = "Atom Sample";
}
```

The page template stays body-only. `PageRenderer` renders the page body first, then passes it to the layout component as default content.

The layout component receives:

```php
public Page $page;
public ?Fragment $content = null;
```

## Components

Components are documented separately:

- [Components](Components.md)

## Page Forms

Small forms can bind directly to page properties:

```php
use Atom\Hydrator\Attributes\FromBody;
use Atom\Page\PageAction;
use Atom\Validation\Rules\Required;

#[FromBody]
#[Required]
public string $titleInput = "";

#[PageAction("create")]
public function create(): void
{
    if (!$this->validate()) {
        return;
    }
}
```

For larger forms, keep the fields in a dedicated model and mark it with `#[FormModel]`.
Combine it with `#[State]` to preserve the model between page actions:

```php
use Atom\Page\FormModel;
use Atom\Page\State;

#[State]
#[FormModel]
public ArticleEditForm $edit;
```

`#[FormModel]` hydrates matching posted fields into the model before the page action runs. This works well with:

```html
<Form submit="save" :model="$this->edit">
    <TextField name="title" label="Title" />
    <TextAreaField name="summary" label="Summary" />
</Form>
```

Page actions can validate the model and expose its errors to field components:

```php
#[PageAction("save")]
public function save(): void
{
    if (!$this->validateModel($this->edit)) {
        return;
    }
}
```

Model properties can use validation attributes:

```php
use Atom\Validation\Rules\MaxLength;
use Atom\Validation\Rules\Required;

final class ArticleEditForm
{
    #[Required("Give this article a title.")]
    #[MaxLength(120)]
    public string $title = "";

    #[Required("Add a short summary.")]
    #[MaxLength(220)]
    public string $summary = "";
}
```

## Native Component Templates

For components that should render with plain PHP, use `ComponentView`.

```php
final class Layout implements ComponentInterface
{
    public Page $page;
    public ?Fragment $content = null;

    public function render(): string
    {
        return ComponentView::render($this);
    }
}
```

Adjacent template:

```text
Layout.atom.php
```

Template variables:

```php
<?php

/** @var \App\Components\Layout $component */
/** @var \Atom\View\Component\ComponentTemplateContext $context */

?>
<title><?= $context->encode($component->title()) ?></title>
<?= $context->fragment($component->content) ?>
```

The context provides:

```php
$context->encode($value)
$context->fragment($fragment, $variables = [])
$context->attributes([...])
$context->classes([...])
```

### Named fragments and outlets

Pass a named fragment as a direct child of a component. A literal `name` matches a public `?Fragment` or `?TemplateFragment` property on that component. For example, declare `public ?Fragment $body = null;` on `DetailPanel`:

```html
<DetailPanel>
    <Fragment name="body">
        <p>{{ $this->product->name }}</p>
    </Fragment>
</DetailPanel>
```

Use `FragmentOutlet` in the component template to place the fragment. Expressions in the supplied fragment still read the calling template's scope. The outlet's own children render as fallback when the fragment is absent. A supplied fragment can render empty output and the outlet will leave it empty.

```html
<FragmentOutlet :fragment="$this->body">
    <p>No delivery details available.</p>
</FragmentOutlet>
```

A self-closing outlet has no fallback. To pass values into a fragment, use an array with `:variables`. For example, an item loop can render the same fragment with a different `$item` each time:

```html
<FragmentOutlet :fragment="$this->content" :variables="['item' => $item]" />
```

The original `<DetailPanel.Body>` syntax also works.

### Conditional attributes

Use `:if="$condition"`, `:else-if="$otherCondition"`, and bare `:else` on sibling elements or components. These compile to the same conditional AST nodes as `@if` and wrap the entire element; the attributes are not rendered.

```html
<p :if="$count > 10">Many</p>
<p :else-if="$count > 0">Some</p>
<p :else>None</p>
```

Branches must be adjacent siblings (whitespace and comments are allowed between them). Whitespace between branches is discarded. Each element accepts one conditional attribute; `:else` takes no value.

### Loop attributes and empty lists

```html
<li :foreach="$users as $id => $user">{{ $id }}: {{ $user }}</li>
<li :empty>No users yet.</li>
```

`:foreach` repeats the entire element or component using the existing `source as $value` or `source as $key => $value` syntax. An optional adjacent bare `:empty` sibling renders when the iterable yields no items, including generators and iterators. Whitespace and comments may separate the siblings; intervening whitespace is discarded. Use one structural attribute per element, nesting elements when combining loops and conditions.

Directive loops also support `@foreach(...) ... @empty ... @endforeach`. Empty branches use the surrounding scope and do not receive loop variables.


### Forms with validation state

`#[FormModel]` properties may extend `Atom\Page\Form`. The form provides
`validate()` and `errors()`, plus protected `setValidation()` and `addError()`
methods for application validation and persistence failures. An application can
implement `save(): bool` to validate, persist, and retain expected errors:

```php
final class ProductForm extends \Atom\Page\Form
{
    #[\Atom\Validation\Rules\Required]
    public string $name = "";

    public function save(): bool
    {
        if (!$this->validate()) return false;
        // Persist using an application service; translate expected failures into errors.
        return true;
    }
}
```

Bind the object with `<Form :model="$this->form" submit="save">`. Atom fields
read errors from their bound form, keeping forms with matching field names
independent. `Page::errors()` includes errors from initialized `#[FormModel]`
forms so `ValidationSummary` continues to work. Form errors remain on the
current request, and `validate()` refreshes the application validation errors. A failed action
can return `null` to render the same hydrated form; a successful action can
redirect. Persistence, transactions, and exception policy stay in application
code, outside the framework.

Form hydration writes public fields only. Keep services, record identifiers,
and validation state private or protected, and establish record context from
the route or loaded domain model. Initialize forms with constructor dependencies
in `init()` after binding the Page's request properties. Atom hydrates posted form
fields after `init()` and before `get()`, so constructor defaults are overlaid by
submitted values and survive failed submissions. Avoid replacing forms in `get()`.
A field omitted from the request keeps the value supplied by `init()` or its
property default, including fields on nested objects. Validation rules such as
`#[Required]` run when an action calls `validate()`. A preview action can skip
validation and use a partial submission. On an edit, `init()` can seed the
existing values from the record or from explicitly restored `#[State]` data.

With multiple forms, each loaded form keeps its values for fields absent from the
request. Form models read from the same POST body, so use distinct field names
when forms need independent values. `#[FormModel]` alone does not carry values
between requests.

A `#[FormModel]` property may be declared as a base form class: when `init()` supplies
a concrete subclass, hydration uses that instance's public fields and keeps its
constructor context intact.

Use bracket notation for nested form fields, such as `<TextField name="address[street]" />`.
Atom binds those fields to the form's public nested object properties and uses
dotted property paths such as `address.street` in validation errors. If the
request field uses an alias, set `bind` to the dotted property path. Initialize
nested objects that need constructor arguments in `init()`. Atom can create
concrete nested objects with no required constructor arguments.

When a supplied value cannot be assigned to its field type, Atom renders the
page with field errors and skips the action. The invalid field keeps its current
typed value, and other valid fields are bound normally.

When `get()` returns an HTTP `Response` during a POST, Atom returns it immediately
without invoking the action.


#### Submitting directly to a form

The existing nested action syntax accepts form methods:

```php
final class ProductForm extends \Atom\Page\Form
{
    #[\Atom\Page\PageAction]
    public function save(): \Atom\Page\FormResult|false
    {
        if (!$this->validate()) return false;
        // Persist through the application's service and retain expected errors.
        return \Atom\Page\FormResult::saved("Product saved.");
    }
}
```

```html
<FormView :form="$this->form" submit="form.save" />
```

`save()` must be explicitly marked `#[PageAction]`; other public form methods are
not exposed. Route discovery includes attributed methods on typed public form
properties, so a Page needs no forwarding action. Returning `false` renders the
submitted Page with its form input and errors. `FormResult::saved()` accepts an
optional success message and an optional local `location` starting with `/`.
Omitting `location` refreshes the submitted URL, including its query parameters.
Native submissions receive a `303` redirect and the notice is carried by the
session. Enhanced submissions on the same path receive fresh HTML with `X-Atom-Uri`.
When the path changes, the client follows the redirect through the destination route.
If a direct save targets a declared nullable form that is now absent, Atom
renders the current Page so it can show its missing-record or recovery state.

Use an explicit Page action for workflows that coordinate multiple steps. When a
Page action calls a form method, it receives the result as an ordinary return
value and chooses its own response. For existing direct form actions, a boolean
`true` from `save()` still calls `Page::formSaved(Form $form): ?Response`. An explicit
HTTP `Response` from the action is returned directly.


A nullable form initialized explicitly to `null` is treated as absent during input
hydration. This allows constructor-initialized editing forms that require a domain
model: create the form in `init()` when a record is selected, before form input
hydration, and leave it `null` otherwise. Atom does not attempt to construct
an absent form or supply its required dependencies. Non-null forms receive posted
public field values over their constructor defaults as usual.


#### Query-bound Page properties

Declare request bindings on Page properties rather than reading query values manually:

```php
#[FromQuery("q")]
public string $search = "";

#[FromQuery]
public string $status = "";
```

Import `Atom\Hydrator\Attributes\FromQuery`. Routed GET rendering and POST actions
hydrate these properties after construction/state restoration and before `init()`.
An omitted query parameter leaves a declared default unchanged. The existing value
coercer trims strings unless `#[Raw]` is used. `#[FromRoute]` properties are also
available before `get()`; body-bound Page properties are not required on GET.
Application code remains responsible for validating allowed filter values.


#### Binding inputs before Page construction

Use request source attributes on constructor parameters when those values are needed
to load models and initialize forms before property hydration:

```php
public function __construct(
    CatalogService $catalog,
    #[FromQuery] int $id = 0,
    #[FromQuery] string $variant = "",
    #[FromQuery("new")] bool $creating = false,
) {
    // The values are already bound/coerced here; services still come from DI.
}
```

Both GET rendering and POST actions bind explicitly attributed constructor parameters
through the existing request hydrator/value coercer before the injector constructs
the Page. Missing inputs preserve parameter defaults. Query-bound parameters read
only from the query, even when POST fields have matching names. `#[FromRoute]` and
other explicit request-source attributes use the same binding contract. Unattributed
parameters continue through normal dependency injection and are not inferred from
request field names. Invalid typed values raise `PageActionException` identifying the
constructor parameter. Property bindings and form hydration follow construction as
usual. The general-purpose DI injector does not depend on HTTP request binding.


#### Initializing a Page

Override `public function init(): void` to prepare models and construct forms using
bound Page properties. Keep constructor parameters for injected dependencies:

```php
#[FromQuery]
public int $id = 0;

#[FormModel]
public ?ProductSeoForm $seoForm = null;

public function init(): void
{
    if ($this->id > 0) {
        $this->seoForm = new ProductSeoForm($this->catalog->product($this->id));
    }
}
```

For each GET render and POST action request the lifecycle is:

1. Construct the Page (constructor request bindings remain supported).
2. Restore `#[State]` values.
3. Bind attributed Page input properties such as `#[FromQuery]` and `#[FromRoute]`.
4. Call `init()` once on that Page instance.
5. Hydrate `#[FormModel]` fields into the forms prepared by `init()`.
6. Call `get()`, then invoke the submitted action on POST.
7. Render, or return the response provided by `get()`/the action.

A failed action renders the existing Page without calling `init()` or `get()` again,
preserving submitted fields and validation errors. `init()` runs on both initial
and enhanced requests; it is not a first-visit hook. It returns `void`; redirects and
other responses belong in `get()` or Page actions. Restored state is available in
`init()`; retain a restored form when its state should survive rather than replacing
it unconditionally. Model and form constructors remain ordinary PHP constructors.

`PageInputHydratorInterface` exposes `hydrateProperties()` and `hydrateForms()` for
the two phases. Custom implementations must implement both phases. The convenience
`hydrate()` method performs both without invoking Page lifecycle hooks itself.


### URI state and automatic action responses

Use `Atom\Page\Uri` on public Page properties to declare URL state. It binds the
incoming matched route parameter (when present) or query value using Atom's existing
value coercer. It never takes the value from POST fields. Use an alias for query names:

```php
#[Uri("q")]
public string $search = "";

#[Uri]
public int $id = 0;

#[Uri("new")]
public bool $creating = false;

#[Uri]
public string $section = "overview";
```

Values are bound before `init()`, just like ordinary `#[FromQuery]` properties. The
additional contract is URL generation: `Page::url()` uses the route matched for the
current request, including prefixes and aliases, rather than choosing a PageRoute
attribute by reflection order. Annotated properties fill matching route placeholders;
the remaining values become an RFC 3986 encoded query. Null/empty values and values
equal to declared defaults are omitted from the query. Backed enums, arrays, booleans,
and wildcard route parameters are supported. Required path values cannot be omitted.

```html
<Button :href="$this->url(['section' => 'seo'])" atom:navigate atom:preserve-scroll>Edit SEO</Button>
```

Overrides may use a property name or its URI alias and do not mutate Page state.
Unknown override names are rejected. Unannotated query parameters are not carried
forward. `#[FromQuery]` remains available for input that should not become URL state.

On a Page with URI bindings, actions can save and update state without constructing
a response or redirect:

```php
#[PageAction]
public function create(): bool
{
    if (!$this->createForm->save()) return false;

    $this->id = $this->createForm->product()->id;
    $this->creating = false;
    $this->section = "details";
    $this->flash("Draft created.");
    return true;
}
```

For an action returning `true`, `null`, or `void` without retained validation errors:

- An ordinary POST returns `303 See Other` to the generated URI. Page flash notices
  are carried to that Page/URI once when a SessionInterface is configured.
- An Atom enhanced submission returns fresh HTML with `X-Atom-Uri`. The client updates
  content and pushes the same-origin URL into browser history, without another HTTP
  request. An unchanged URL adds no history entry; back/forward uses normal GETs.
- Returning `false` or retaining validation errors renders the original Page without
  redirecting, publishing a URI header, or replacing the submitted form values.
- An explicit Response takes precedence. Pages without URI bindings retain their
  existing action-response behavior.

Enhanced success renders a fresh Page instance internally from the updated URI,
carrying its current State values and flash notice. This ensures loaded records,
forms, and lists reflect saved data. `init()` runs once per Page instance; a success
snapshot therefore initializes the fresh instance as well. Failed submissions never
initialize again. Keep database writes in actions/forms, not `init()` or `get()`.


### Missing records in Pages

Use the protected `notFound()` helper when the selected record does not exist:

```php
public function init(): void
{
    $this->product = Product::find($this->id);
    if ($this->product === null) $this->notFound("Product not found.");
}
```

The helper returns `never`: it throws the existing ErrorPages `HttpException` with
status 404 and immediately stops initialization/action processing. The configured
error-page handler renders the response, including custom handlers. Atom's default
handler produces an HTML error document, or JSON when requested by the Accept
header. This works for GET, ordinary POST, and enhanced requests; the failed request
never reaches URI-success redirects or form saving. Pass an empty message to use
the default 404 description. The helper is not a page action and cannot be invoked
through submitted action names. Other HTTP errors can use `HttpException` directly.
