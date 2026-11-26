# Atom View

Atom View parses `.atom.html` templates and renders PHP components. The package requires PHP 8.4 and is available locally in the Atom monorepo.

To use it from another local Composer project, add the absolute path to this directory as a Composer path repository and require `atom-fm/view` at `^0.1@dev`. The monorepo already configures this path for its bundled projects.

```php
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;

$template = (new ViewParser())->parse('<h1>Hello {{ $name }}</h1>');
echo (new ViewRenderer())->render($template, ["name" => "Atom"]);
```

Register reusable component tags with `ComponentRegistry`. A `TemplateComponent` loads the adjacent `.atom.html` file and caches its parsed template by path and modification time.
