# Components

[Atom Framework](Index.md)

Components are the reusable building blocks of `.atom.html` views.

They are intentionally simple PHP objects:

- attributes hydrate public properties
- default child content hydrates `public ?Fragment $content` or `public ?TemplateFragment $content`
- named fragments hydrate matching `Fragment` or `TemplateFragment` properties
- unknown attributes can be captured with `AttributeBag`
- declared child tags can be collected into typed arrays with `#[Children]`
- child fragments are lazy, so the parent decides if and when they render

The optional [Atom Components package](../packages/components/readme.md) provides ready-made widgets, styles, and browser controls.

## Register Components

Register a component when you want a short tag name:

```php
use App\Components\Button;
use Atom\View\Component\ComponentRegistry;

protected function components(ComponentRegistry $components): void
{
    $components->register("Button", Button::class);
}
```

Then use it from a template:

```html
<Button label="Save" />
```

## Component Sets

Use `ComponentSet` when a package or module provides several tag mappings as one reusable definition:

```php
use Atom\View\Component\ComponentSet;

final readonly class BlogComponents
{
    public static function definitions(): ComponentSet
    {
        return ComponentSet::from([
            "Blog.ArticleCard" => ArticleCard::class,
            "Blog.CategoryBadge" => CategoryBadge::class,
        ]);
    }
}
```

A registry can import the complete set:

```php
$components->import(BlogComponents::definitions());
```

Modules import sets through their context:

```php
public function register(ModuleContext $context): void
{
    $context->importComponents(BlogComponents::definitions());
}
```

Sets are immutable. Add a definition by creating a derived set with `with()`, or combine disjoint sets with `merge()`. Duplicate tags and classes that do not implement `ComponentInterface` fail immediately. Registry imports are atomic: if any imported tag is already registered, none of the set is added.

The built-in `Components`, `Client`, `Accounts`, and `ApiExplorer` modules expose their mappings through a `definitions()` method. Module imports are local by default; use `ModuleContext::exportComponents()` only for definitions intended for application templates.

## Registry Scopes

Application components live in the global registry. Each module owns a sealed child registry:

```text
Module component registry
    ├── private module definitions
    ├── privately imported component sets
    └── parent: application registry
```

Resolution checks the module registry first and then its application parent. This allows a module to use a stable local `Table` definition even when the application has registered another component with the same tag. Private module tags are not visible to application pages or other modules.

The registry is sealed after module registration, preventing code that later discovers the module through a route from changing its component definitions.

Framework internals may also use component class names directly, but application templates should usually prefer registered names.

The Components module registers its default UI and form component set:

```html
<Button>Save</Button>
<Button variant="link" atom:action="edit(1)">Edit</Button>
<Alert variant="danger">Could not save.</Alert>
<Badge>Published</Badge>
<Avatar name="Ada Lovelace" />
<Tag variant="success">Stable</Tag>
<StatusDot variant="success" label="Online" />
<Kbd>Ctrl</Kbd>
<Divider>or</Divider>
<Details summary="Advanced options">...</Details>
<Panel title="Articles">...</Panel>
<Drawer :model="$this->drawer" title="Article details" closeAction="drawer.close()">...</Drawer>
<Stack gap="sm">...</Stack>
<Inline align="center" justify="between">...</Inline>
<Form submit="save" csrf>...</Form>
<FormActions>...</FormActions>
<Field label="Title" name="title">...</Field>
<TextInput name="title" maxlength="120" />
<TextArea name="body" rows="8" />
<TextField label="Title" name="title" maxlength="120" />
<TextAreaField label="Body" name="body" />
<SelectField label="Category" name="category_id" bind="categoryId" :options="$this->categories" />
<PasswordField label="Password" name="password" autocomplete="current-password" />
<FileField label="Attachment" name="attachment" accept=".pdf,image/*" />
<InputGroup>...</InputGroup>
<CheckField label="Published" name="isPublished" />
<HiddenField name="id" />
<FieldError name="title" />
<ValidationSummary />
```

Use the optional asset components from an `.atom.html` layout when the Client and Components modules are registered:

```html
<!doctype html>
<html lang="en">
<head>
    <ComponentsStyles />
</head>
<body>
    {{ $context->fragmentHtml($this->content) }}
    <ClientScripts morphdom />
</body>
</html>
```

`ClientScripts` always loads the Atom runtime. Set `morphdom` to also load MorphDOM and the Atom adapter. Both asset components accept a custom `resource-path`; their default paths match the module defaults.

`Avatar` renders an image when `src` is provided and otherwise derives up to two initials from `name`. `StatusDot` requires a `label` when its meaning is not also written next to it. `Details` uses native `<details>` disclosure behavior and does not require JavaScript.

## Pagination with Page URI state

Return a `PagedCollection` from the listing query with the rows, total count,
current page, and page size. Use `PagedCollection::clampPage($requestedPage, $totalCount, $pageSize)`
before calculating the query offset. Empty results use page 1, and other requests
are clamped between the first and last pages. Calling `map()` to build display rows
preserves this pagination metadata. `Table.Pagination` supplies the table's
collection as the pager's source.

```html
<Table :items="$this->rows">
    <Column label="Name" field="name" />
    <Table.Pagination><Pagination query-key="page" navigate preserve-scroll /></Table.Pagination>
</Table>
```

`query-key` accepts a Page's `#[Uri]` property name or its query alias. Each link
uses `Page::url()` to change that value and carry the other bound values and route
parameters forward. Use `:uri-overrides="['selection' => null]"` when changing
pages should clear a selection. Outside a table, pass the collection with
`:source`, for example `<Pagination :source="$this->images" query-key="imagepage" navigate preserve-scroll />`.
The links work as native GETs. `navigate` also lets Atom load them through its
enhanced navigation. The `href` template and `action` modes remain available.

## Icons

`Icon` supports child text or markup, public SVG URLs, local SVG paths resolved through `Paths`, data URIs, and font-icon class names. Named icon catalogues are optional packages registered by prefix.

Custom catalogues implement `IconProviderInterface` and register an `IconResolverInterface` with `IconRegistry`. Framework provides these types under `Atom\Modules\Icons`.

Install Lucide separately when an application wants the `lucide:` catalogue:

```powershell
composer require atom-fm/lucide
```

```php
use Atom\Di\ServiceProviderRegistry;
use Atom\Lucide\LucideServiceProvider;

protected function services(ServiceProviderRegistry $providers): void
{
    $providers->add(LucideServiceProvider::class);
}
```

The registered prefix works anywhere a Components module component accepts an icon:

```html
<Icon icon="lucide:search" />
<Button icon="lucide:arrow-right">Continue</Button>
```

The core framework does not bundle any named icon catalogue. Using an unregistered prefix produces an explicit error.

Use `Badge` for compact status or metadata, and `Tag` for pill-shaped classification labels. `Divider` supports `horizontal` and `vertical` orientations.

Tables provide padded rows, neutral content links with visible keyboard focus, and a full-width toolbar area for either native forms or `Form`. A toolbar inside `Table.Toolbar` uses a plain surface by default; set its `appearance` explicitly to retain a boxed treatment. Use `Toolbar.Start` for fields and `Toolbar.End` for grouped commands. Sections inherit the toolbar's alignment and wrap on narrow screens without page-specific CSS. Use `Badge appearance="outline"` when table statuses need a visible boundary; plain badges remain the default elsewhere.

`AppShell.Navbar` spans the shell above both the sidebar and content. The existing `AppShell.Header` stays above the content column. `Navbar` provides `Navbar.Brand`, regular content, and `Navbar.Actions` fragments, with wrapping, spacing, and keyboard focus styles included. Set `label` on `Navbar` and `Sidebar` to name their navigation landmarks. Shell sidebars move above the content when the shell is 720px wide or less, with navigation columns fitting the available space; this responds to the shell container rather than the browser width.

```html
<AppShell>
    <AppShell.Navbar>
        <Navbar label="Workspace">
            <Navbar.Brand><a href="/">Support Desk</a></Navbar.Brand>
            Inbox
            <Navbar.Actions><Button href="/account">Account</Button></Navbar.Actions>
        </Navbar>
    </AppShell.Navbar>
    <AppShell.Sidebar>
        <Sidebar label="Queues">
            <SidebarGroup label="Tickets">
                <SidebarItem href="/tickets" active>All tickets</SidebarItem>
                <SidebarItem href="/tickets?status=open">Open</SidebarItem>
            </SidebarGroup>
        </Sidebar>
    </AppShell.Sidebar>
    <PageHeader title="Ticket inbox" />
</AppShell>
```

`Drawer` is a modal side surface with `start` or `end` placement, `sm`, `md`, or `lg` sizing, and an optional `Drawer.Actions` fragment. Bind a `DrawerModel` when page actions should open and close it while preserving an associated value.

`Tab` accepts optional `icon`, `badge`, and `badgeVariant` values. Icons use the same resolver as the standalone `Icon` component, while tab badges render with the compact soft badge style.

`TextInput` and `TextArea` read values from the current page property matching `name`, unless a `value` attribute is provided. They render framework control classes such as `atom-input` and `atom-textarea`. When validation errors exist, they render `aria-invalid`, `aria-describedby`, and the `is-invalid` class.

`TextField`, `TextAreaField`, `SelectField`, `CheckField`, `RadioField`, and `SwitchField` are composite field entries. They render the field wrapper, control, validation state, help text, and field error.

Composite fields forward ordinary HTML attributes to their controls. Use native attributes such as `required`, `readonly`, `disabled`, `placeholder`, and `autocomplete` instead of framework-specific equivalents:

```html
<TextField label="Email" name="email" type="email" autocomplete="email" required />
<TextField label="Generated slug" name="slug" readonly />
<SwitchField label="Unavailable" name="enabled" disabled />
```

`TextField` and `TextAreaField` can use `infer-max-length` to render the HTML `maxlength` attribute from `#[MaxLength]` on the bound public property. Atom uses the field's `bind` or `name` to find the property on the object model, or on the page when there is no model. An explicit `maxlength` takes precedence, and a bound `:maxlength="null"` suppresses the attribute. Form validation still runs on the server, and conditional rules stay in the form.

```html
<TextField label="Title" name="title" infer-max-length />
<TextAreaField label="Body" name="body" infer-max-length />
```

Use `InputGroup` with `InputGroup.Prefix` and `InputGroup.Suffix` fragments when a raw control needs an icon, unit, URL segment, or other compact adornment:

```html
<Field label="Website" name="website">
    <InputGroup>
        <InputGroup.Prefix>https://</InputGroup.Prefix>
        <TextInput name="website" />
        <InputGroup.Suffix>.com</InputGroup.Suffix>
    </InputGroup>
</Field>
```

`PasswordField` renders a password input with a progressively enhanced reveal control by default. Set `reveal` to `false` when the toggle is not appropriate. `FileField` provides the standard composite-field label, help, and validation structure around a native file input. Forms that submit files must use `enctype="multipart/form-data"`.

`CheckField` renders a hidden unchecked fallback value before the checkbox, so boolean form models receive `"0"` when the box is unchecked and `"1"` when checked.

`HiddenField` binds from the current page or form model like the other field components, but renders only a hidden input without visible field chrome.

Set `csrf` on a non-GET `Form` to prepend the session-backed `_token` field. The target route or route group must use `CsrfMiddleware` to validate it.

## Properties

Attributes are assigned to public properties.

```php
use Atom\View\Component\ComponentInterface;

final class Button implements ComponentInterface
{
    public string $label = "";
    public bool $disabled = false;

    public function render(): string
    {
        $disabled = $this->disabled ? " disabled" : "";

        return "<button{$disabled}>{$this->label}</button>";
    }
}
```

```html
<Button label="Save" :disabled="$saving" />
```

Attribute names are normalized to property names:

```html
<Button button-kind="primary" />
```

hydrates:

```php
public string $buttonKind = "";
```

Required public typed properties without defaults must be provided:

```php
public string $label;
```

## Attribute Bag

Use `AttributeBag` when a component should forward arbitrary HTML attributes.

```php
use Atom\View\Component\AttributeBag;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;
use Atom\View\Html;

final class Button implements ComponentInterface
{
    public AttributeBag $attributes;
    public ?Fragment $content = null;

    public function render(): string
    {
        return Html::tag("button", $this->attributes->all(), $this->content?->render() ?? "");
    }
}
```

```html
<Button id="save" class="primary" disabled>Save</Button>
```

`AttributeBag` properties are initialized automatically during component hydration. Components only need to declare:

```php
public AttributeBag $attributes;
```

## HTML Helpers

Small framework components are usually built with `Html` helpers instead of hand-written string concatenation.

```php
use Atom\View\Html;

return Html::tag("button", Html::mergeAttributes([
    "type" => "button",
    "class" => Html::classes("atom-button", $this->class),
    "data-variant" => $this->variant,
], $this->attributes->all()), $this->content?->render() ?? "");
```

Available helpers:

```php
Html::escape($value)
Html::attribute("disabled", true)
Html::attributes(["class" => "button", "disabled" => true])
Html::tag("div", ["class" => "panel"], $content)
Html::voidTag("input", ["name" => "title"])
Html::classes("atom-button", ["is-active" => $active], $extraClass)
Html::mergeAttributes($defaults, $attributes)
```

`Html::tag()` does not escape content. Pass escaped text or rendered HTML intentionally.

The HTML helpers reject malformed attribute names. An empty string renders as an explicit attribute value. When using `Html::mergeAttributes()`, empty defaults are omitted and explicitly supplied empty overrides are kept.

## Default Content

Default child content is assigned to `content`.

```php
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;

final class Card implements ComponentInterface
{
    public ?Fragment $content = null;

    public function render(): string
    {
        return "<section>" . $this->content?->render() . "</section>";
    }
}
```

```html
<Card>
    <p>Hello {{ $name }}</p>
</Card>
```

Fragments are lazy. The component controls when the content is rendered and what variables are available:

```php
$this->content?->render(["name" => "Ada"]);
```

This is also how parent components can provide data to their child content. `Form`, for example, passes a `model` variable into its child fragment when `model` is set.

Useful helpers:

```php
$this->content?->isEmpty();
$this->content?->renderOr("<p>Empty</p>");
```

Whitespace-only output counts as empty.

Use `TemplateFragment` when a parent component needs to inspect the child template as well as render it.
This is useful for showcase/documentation components that render a live preview and source code:

```php
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\TemplateFragment;
use Atom\View\Html;

final class ComponentExample implements ComponentInterface
{
    public string $title = "";
    public ?TemplateFragment $content = null;

    public function render(): string
    {
        return Html::tag("section", ["class" => "component-example"],
            Html::tag("h2", content: Html::escape($this->title)) .
            Html::tag("div", ["class" => "component-example-preview"], $this->content?->render() ?? "") .
            Html::tag("pre", content: Html::escape($this->content?->source() ?? ""))
        );
    }
}
```

Template fragments expose:

```php
$this->content?->render($variables)
$this->content?->source()
$this->content?->nodes()
$this->content?->fragment()
```

The first version of `source()` serializes the parsed AST. It is intended for readable examples, not exact whitespace preservation.

## Context Injection

Use `#[FromContext]` when a child component should receive a value provided by a parent fragment render.

```php
use Atom\View\Component\FromContext;

final class TextField extends FieldEntry
{
    #[FromContext("model")]
    public mixed $model = null;
}
```

Explicit attributes win over context values:

```html
<TextField :model="$otherModel" name="title" />
```

Without an explicit attribute, context can fill the property:

```html
<Form :model="$this->editForm" submit="save">
    <TextField label="Title" name="title" />
    <TextAreaField label="Body" name="body" />
</Form>
```

For non-GET forms, `submit="save"` emits both `atom:submit="save"` and a hidden
`_action=save` input. The hidden input preserves page-action dispatch when JavaScript
is disabled. Add `csrf` to emit the session CSRF token as well. Ordinary forms without
`submit`, and GET forms, do not receive a page-action input.

`Form` renders its child fragment with:

```php
$this->content?->render(["model" => $this->model]);
```

`FieldEntry` uses the context model before falling back to the page property. This avoids prop drilling in form components while keeping data flow explicit.

## Named Fragments

Named fragments use dotted child elements.

Declare a named fragment directly inside its component. Put conditions or loops inside the fragment body.

```php
final class Panel implements ComponentInterface
{
    public ?Fragment $header = null;
    public ?Fragment $content = null;

    public function render(): string
    {
        return "<section>"
            . "<header>" . $this->header?->render() . "</header>"
            . "<div>" . $this->content?->render() . "</div>"
            . "</section>";
    }
}
```

```html
<Panel>
    <Panel.Header>
        <h2>Users</h2>
    </Panel.Header>

    <p>Panel body.</p>
</Panel>
```

`<Panel.Header>` hydrates `public ?Fragment $header` or `public ?TemplateFragment $header`.

## Typed Children

Use `#[Children]` when child tags should become typed objects instead of immediate output.

This is useful for components like tables, menus, tabs, forms, or anything where the parent interprets child declarations.

```php
use Atom\View\Component\Children;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\Fragment;

final class Table implements ComponentInterface
{
    /** @var Column[] */
    #[Children("Column", Column::class)]
    public array $columns = [];

    /** @var array<object> */
    public array $items = [];

    public function render(): string
    {
        $html = "<table><thead><tr>";

        foreach ($this->columns as $column) {
            $html .= "<th>" . $column->header() . "</th>";
        }

        $html .= "</tr></thead><tbody>";

        foreach ($this->items as $item) {
            $html .= "<tr>";

            foreach ($this->columns as $column) {
                $html .= "<td>" . $column->cell()->render(["item" => $item]) . "</td>";
            }

            $html .= "</tr>";
        }

        return $html . "</tbody></table>";
    }
}

final class Column implements ComponentInterface
{
    public string $title = "";
    public ?Fragment $header = null;
    public ?Fragment $cell = null;
    public ?Fragment $content = null;

    public function render(): string
    {
        return "";
    }

    public function header(): string
    {
        return $this->header?->renderOr($this->title) ?? $this->title;
    }

    public function cell(): Fragment
    {
        return $this->cell ?? $this->content ?? new Fragment(static fn(): string => "");
    }
}
```

Template:

```html
<Table :items="$users">
    <Column title="Name">
        {{ $item->name }}
    </Column>

    <Column>
        <Column.Header>Email</Column.Header>
        <Column.Cell>{{ $item->email }}</Column.Cell>
    </Column>
</Table>
```

`Column` is not rendered automatically. It is collected into `Table::$columns`, and `Table` decides:

- whether each column renders
- where each column renders
- how many times each fragment renders
- which variables each fragment receives

This keeps child components useful as declarative configuration, not only as standalone HTML emitters.

## Render Results

A component may return:

```php
string
TemplateNode
ViewNodeInterface
array<ViewNodeInterface>
```

Most application components return strings or use a native component template.

## Atom HTML Component Templates

For `.atom.html` component templates, extend `TemplateComponent` and place a template with the same base filename beside the component class:

```php
use Atom\View\Component\TemplateComponent;

final class EndpointList extends TemplateComponent
{
    public array $endpoints = [];
}
```

```text
EndpointList.php
EndpointList.atom.html
```

The template receives:

```php
$this
$component
$context
```

and public component properties as variables.

Use `$context->fragmentHtml($this->content)` when a template component needs to render a child fragment as HTML.

## Native Component Templates

For larger HTML output, a component can render an adjacent `.atom.php` template.

```php
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\ComponentView;
use Atom\View\Component\Fragment;

final class Layout implements ComponentInterface
{
    public string $title = "";
    public ?Fragment $content = null;

    public function render(): string
    {
        return ComponentView::render($this);
    }
}
```

Adjacent file:

```text
Layout.atom.php
```

Template:

```php
<?php

/** @var \App\Components\Layout $component */
/** @var \Atom\View\Component\ComponentTemplateContext $context */

?>
<title><?= $context->encode($component->title) ?></title>
<?= $context->fragment($component->content) ?>
```

The template context provides:

```php
$context->encode($value)
$context->fragment($fragment, $variables = [])
$context->attributes([...])
$context->classes([...])
```

## Error Messages

Component authoring errors are intended to point at the fix.

Examples:

- unknown attributes suggest adding a public property or `AttributeBag`
- unknown named fragments suggest adding the matching `Fragment` or `TemplateFragment` property
- body content without `public ?Fragment $content` or `public ?TemplateFragment $content` fails clearly
- invalid `#[Children]` mappings name the mapped property
- invalid render results include the returned type


### FormView

Use `FormView` to render an `Atom\Page\Form` with its adjacent template:

```html
<FormView :form="$this->detailsForm" submit="detailsForm.save" class="product-editor" />
```

For `ProductDetailsForm.php`, the default template is `ProductDetailsForm.atom.html`
in the same folder. A form may override `template(): ?string` with a relative or
absolute path. The framework loads it through the existing template cache and renders
it using the current component registry, including module-local components.

The form template contains fields and buttons, without another `<Form>` wrapper.
`$this` and `$form` refer to the form; `$page` remains the containing Page. Native
fields inherit the form model and display its retained validation errors. Prefer
form methods for form-specific choices and use `$page` for page navigation.

`FormView` supplies a POST `Form` with CSRF by default. Pass `submit` to select the
action; Atom supplies both enhanced submission and the hidden `_action` input
for ordinary POSTs. Use `submit="detailsForm.save"` to call a public Page form's
`#[PageAction] save(): bool` directly. A `false` return re-renders the Page with the
form's retained input and errors. A `true` return invokes the Page's optional
`formSaved(Form $form): ?Response` hook once. That shared hook can set a success
notice and redirect, or return `null` to re-render. No forwarding Page action is
required. Plain Page actions remain available when custom orchestration is needed.
HTML attributes are forwarded to the outer form. `method="get"` and `action` are
supported for search forms; GET forms emit neither CSRF nor page-action inputs.
Keep the element self-closing; markup belongs in the form's template.

Group feature pages and forms together, for example:

```text
Pages/Catalog/
  Products.php
  Products.atom.html
  ProductDetailsForm.php
  ProductDetailsForm.atom.html
```

Page discovery is recursive and only registers routed Page subclasses, so colocated
form classes need no registration. Domain models and reusable application services
remain outside the Pages folder.

## Adjacent component styles

Place `UserCard.css` beside `UserCard.php`. Registered application and module components (including private module components) are scanned without instantiation. Missing CSS is normal. Files are deduplicated by resolved path and sorted by path; CSS remains global, so use component-specific class names. The bundle contains all registered component styles, regardless of which components a request renders. Existing framework `ComponentsStyles` CSS remains separate.

Enable the development endpoint by registering this module in your application's `modules()` method:

```php
$modules->add(new \Atom\Modules\ComponentStyles\ComponentStylesModule(development: true));
```

Include the bundle once in the layout:

```html
<link rel="stylesheet" href="/assets/components.css">
```

The endpoint recomposes CSS on every request and returns `Cache-Control: no-cache`. Enable it only in development and remove any previously built public file when switching back to development, since static files may take precedence. An optional `path` constructor argument changes the route (relative to the module mount).

For production, omit the development endpoint and run:

```powershell
php atom styles:build
php atom styles:build --output=@public/assets/components.css
```

Framework applications register the build command through `Atom\Modules\ComponentStyles\ComponentStylesServices`. The command uses the same scanner and composer and atomically writes `@public/assets/components.css`. Rebuild during deployment and update your asset version when the content changes. CSS must be UTF-8, use root-relative or absolute `url(...)` references, and contain no `@import` or `@charset` declarations; unsupported declarations or relative URLs produce build errors rather than broken asset references. Styles are concatenated without minification.
