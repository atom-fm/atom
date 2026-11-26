# Atom Components

Server-rendered widgets, styles, and browser controls for Atom Framework.

Install the package in your application.

```bash
composer require atom-fm/components
```

Enable it by adding its module to the application's module registry.

```php
use Atom\Components\Components;
use Atom\Integration\View\ComponentModule;

$modules->add(new ComponentModule(
    Components::definitions(),
    Components::resourceDirectory(),
    Components::DEFAULT_RESOURCE_PATH
));
```

Load the supplied stylesheet in the layout. Load the component scripts after the framework's browser runtime for password reveal and the Lookup and DatePicker controls.

```html
<ComponentsStyles />
<ClientScripts morphdom />
<ComponentsScripts />
```

Register the framework's Client module to use `ClientScripts`. The component and client resources are served under their respective module paths.

## Develop in the monorepo

Run `composer setup` from the repository root to install the package and showcase. Run `composer components:check` for component PHP tests and static analysis.

Start the [showcase](https://github.com/atom-fm/atom/tree/master/apps/showcase) from its directory.

```bash
php -S 127.0.0.1:8022 -t public
```

See the [browser controls guide](https://github.com/atom-fm/atom/blob/master/docs/client-components.md) for the demo and browser tests.
