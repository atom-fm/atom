# Atom Lucide Icons

Lucide provides the SVG catalogue for the `lucide:` icon prefix. The monorepo version uses `atom-fm/view` for icon resolution and `atom-fm/di` for its service provider. Published Lucide 0.2 releases require Atom Framework 0.2. Use Lucide 0.1 with Atom Framework 0.1.

```powershell
composer require atom-fm/lucide
```

### Standalone use in the monorepo

Configure Composer path repositories for Lucide, View, and DI, with development versions enabled. Register the Lucide provider with a View icon registry:

```php
use Atom\Lucide\LucideServiceProvider;
use Atom\View\Icons\IconRegistry;

$icons = new IconRegistry();
(new LucideServiceProvider())->icons($icons);
echo $icons->resolve("lucide", "search");
```

### Framework applications

Framework registers the icon catalogue automatically when you add the provider to your application:

```php
use Atom\Di\ServiceProviderRegistry;
use Atom\Lucide\LucideServiceProvider;

protected function services(ServiceProviderRegistry $providers): void
{
    $providers->add(LucideServiceProvider::class);
}
```

Use the prefix with Atom components:

```html
<Icon icon="lucide:search" />
<Button icon="lucide:arrow-right">Continue</Button>
```

The SVG catalogue is imported from `lucide-static` 1.31.0. See `UPSTREAM.md` and `LICENSE-LUCIDE` for provenance and licensing.
