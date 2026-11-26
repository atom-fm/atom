# Modules

[Atom Framework](Index.md)

Modules are pluggable framework or application features. A module can register bindings, routes,
components, resources, pages, commands, background jobs, and scheduled tasks.

## Mount an editorial checklist

Package a publishing checklist with a small module. An editor can request the module's URL to discover where to fetch the current checklist. The application chooses the URL prefix when it installs the module.

In an Atom Starter project, create `app/BlogTools/UI/Resources/checklist.txt` with these two lines.

```text
Confirm the headline.
Check the links.
```

Create `app/BlogTools/BlogToolsModule.php` beside the `UI` directory.

```php
<?php

declare(strict_types=1);

namespace App\BlogTools;

use Atom\Http\Response;
use Atom\Module\ModuleContext;
use Atom\Module\ModuleInterface;
use Atom\Router\RouteEntry;

final class BlogToolsModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->resources("/resources", __DIR__ . "/UI/Resources");
        $checklist = $context->resourcePath("/resources", "checklist.txt");

        $context->route(RouteEntry::get(
            $context->mountedPath(),
            static fn(Response $response): Response => $response->json(["checklist" => $checklist])
        ));
    }
}
```

In `app/Application.php`, import `App\BlogTools\BlogToolsModule` and add this line inside the Starter's existing `modules(ModuleRegistry $modules)` method.

```php
$modules->add(new BlogToolsModule(), "/tools/blog");
```

Run `php atom serve` from the project root. In a second PowerShell terminal, request the module index and follow the resource URL it returns.

```powershell
$site = "http://127.0.0.1:8020"
$index = Invoke-RestMethod "$site/tools/blog"
$index.checklist
curl.exe -fsS "$site$($index.checklist)"
```

The first output line is `/tools/blog/resources/checklist.txt`, followed by the two checklist lines. To move the feature, change the mount path in `app/Application.php` to `/editorial` and request `$site/editorial`. The index now points to `/editorial/resources/checklist.txt` because `mountedPath()` and `resourcePath()` both use the registered prefix.

## Register module features

Modules are registered after the injector and router are ready and before `bootstrap()` runs. `registerModule()` is still available when a module must be registered manually.

`ModuleContext` currently exposes:

```php
$context->router;
$context->injector;
$context->components;
$context->bindings;
$context->basePath;

$context->mountedPath();
$context->mountedPath("/resources/app.css");
$context->resourcePath();
$context->resourcePath("/assets", "app.css");
$context->root();
$context->bind(SomeService::class)->toSelf()->singleton();
$context->route($entry);
$context->component("TagName", ComponentClass::class);
$context->component("Feature.AppShell", AppShell::class);
$context->commands(__DIR__ . "/Commands", __NAMESPACE__ . "\\Commands");
$context->jobs(FeatureJob::class);
$context->schedule(static function (Schedule $schedule): void {
    $schedule->command("feature:cleanup")->daily();
});
$context->pages(__DIR__ . "/UI/Pages", [
    ModulePageMiddleware::class,
]);
$context->resources("/resources", __DIR__ . "/UI/Resources");
```

Command directories registered through `ModuleContext::commands()` are added to the normal console discovery sources. A console resolved earlier during bootstrap discovers these directories as modules register them. Commands can extend `Command` or expose methods marked with `#[ConsoleCommand]`. Only installed modules contribute their commands.

```php
final readonly class FeatureCommands
{
    #[ConsoleCommand("feature:publish", "Publish feature scaffolding")]
    public function publish(): int
    {
        return 0;
    }
}
```

Register module commands during normal application initialization, before `ConsoleApplication` is first resolved.

The module convention is:

```text
Feature/
  UI/
    Pages/
    Components/
    Resources/
```

Resources are served through the framework static file handler. A resource directory registers one wildcard `GET` route under the given path. With a module base path of `/tools/blog`, this call:

```php
$context->resources("/resources", __DIR__ . "/UI/Resources");
```

serves:

```text
/tools/blog/resources/app.css
```

for:

```text
UI/Resources/app.css
```

Use `resourcePath()` when a component or page needs to render a URL to a mounted resource:

```php
$context->resourcePath(); // /tools/blog/resources
$context->resourcePath("/assets", "app.css"); // /tools/blog/assets/app.css
```

Registering the same resource route more than once returns the existing route instead of creating a duplicate.

Use `root()` when a module needs to register shared routes or resources outside its mounted path:

```php
Client::resources($context->root());
```

The optional client module serves the Atom browser runtime and MorphDOM integration:

```php
use Atom\Modules\Client\Client;

Client::resources($context);
```

Its default URLs are:

```text
/atom/client/resources/atom.js
/atom/client/resources/morphdom.js
/atom/client/resources/atom-morphdom.js
```

The optional components module registers the shared PHP component tags and serves their CSS and icons:

```php
use Atom\Components\Components;

$modules->add(Components::module());
```

Its resources are exposed at:

```text
/atom/components/resources/atom.css
/atom/components/resources/icons/...
```

Register either module independently, or register both when an application uses both layers.

Pages discovered through `pages()` are registered under the context base path.

Then a page declared with:

```php
#[PageRoute("/settings")]
```

is served from:

```text
/tools/blog/settings
```

Components can use dotted names to keep module UI tags grouped:

```php
$context->component("BlogTools.AppShell", AppShell::class);
```

Components registered through a module context are private to that module. Every module receives a sealed child registry that falls back to application-global components. A local definition takes precedence over a global tag with the same name, without changing the application registry.

When a module provides several components, expose them as a reusable `ComponentSet` and import the set through the context:

```php
public static function definitions(): ComponentSet
{
    return ComponentSet::from([
        "BlogTools.AppShell" => AppShell::class,
        "BlogTools.ArticleList" => ArticleList::class,
    ]);
}

public function register(ModuleContext $context): void
{
    $context->importComponents(BlogTools::definitions());
}
```

The set separates component definitions from where they are registered. Another module can import the same mappings without exposing those tags to the application.

Use an explicit export only when a module intentionally provides tags to ordinary application templates:

```php
$context->exportComponent("BlogCard", BlogCard::class);
$context->exportComponents(BlogPublicComponents::definitions());
```

The built-in Components and Client modules export their definitions because applications register them specifically to use their tags. Feature modules such as Accounts and API Explorer keep their implementation components private.

Then a module page template can render:

```html
<BlogTools.AppShell>
    <h1>{{ $this->title }}</h1>
</BlogTools.AppShell>
```

## Module Route Scope

Every route, discovered page, page action, and new resource route registered through `ModuleContext` receives the same immutable `ModuleScope` metadata. A matched route exposes its owner directly:

```php
$scope = $matchedRoute->moduleScope();

$scope?->id;
$scope?->module;
$scope?->basePath;
$scope?->components;
```

During dispatch, `ModuleScope` and its `ComponentRegistry` are available through dependency injection. Page rendering automatically uses that registry, including nested components and layouts. Routes registered directly on the application router have no module scope and use the application registry.

Calling `$context->root()` changes only where a module registers a route; it preserves the owning module scope. If a shared resource route already exists, its original owner remains unchanged.

Modules can also register services into the application container:

```php
final class BlogToolsModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->bind(BlogIndexer::class)->toSelf()->singleton();
    }
}
```

Module bindings are added to the same application container used by routes, pages, command handlers, and components.

When providers and modules register the same token, the last binding registered before resolution wins. Once the owning injector has created a singleton for that token, it continues returning that instance for its lifetime, even if the binding is replaced later. Register singleton overrides before the first lookup.

```php
$context->bind(Mailer::class)->to(FakeMailer::class);
```
