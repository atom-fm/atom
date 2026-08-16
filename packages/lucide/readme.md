# Atom Lucide Icons

Lucide icon integration for Atom Framework. The package owns the `lucide:` icon prefix and keeps the icon catalogue out of the core framework package.

```powershell
composer require atom-fm/lucide
```

Register the provider from the application:

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
