# Atom View

Atom View parses `.atom.html` templates and renders PHP components. The package requires PHP 8.4 and is available locally in the Atom monorepo.

To use it from another local Composer project, add the absolute path to this directory as a Composer path repository and require `atom-fm/view` at `^0.1@dev`. The monorepo already configures this path for its bundled projects.

```php
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;

$template = (new ViewParser())->parse('<h1>Hello {{ $name }}</h1>');
echo (new ViewRenderer())->render($template, ["name" => "Atom"]);
```

## Supported API

View is in maintenance mode. Routine changes focus on fixes, compatibility, and documentation for the APIs below. The package is still used from the monorepo during local development.

| Area | Supported API |
| --- | --- |
| Rendering | `ViewParser::parse()` and `parseFile()` produce a parsed template. `ViewRenderer::render()` takes a parsed template, `renderFile()` takes a template path, and `renderComponent()` takes a component class and its properties. Render variables can be supplied as an array or a `ViewContext`. |
| Component registration | `ComponentRegistry` registers component tags, imports a `ComponentSet`, and supports parent registries and sealing. Pass a registry with `new ViewRenderer(components: $registry)` or `withComponents($registry)`. |
| Component authoring | Implement `ComponentInterface` to render HTML, extend `TemplateComponent` to load an adjacent `.atom.html` file, or use `ComponentView::render()` to render an adjacent `.atom.php` file. Public properties receive component inputs. `FromContext` supplies defaults from render context, `Children` collects typed child components, and `AttributeBag` receives extra attributes. |
| Composition | `Fragment`, `TemplateFragment`, and `<FragmentOutlet>` support default and named content, rendered on demand. Fragment rendering can accept variables for nested content. |
| HTML output | Use `Html` to escape text, build tags, or serialize and merge attributes. Use `HtmlString` or `$context->raw()` for trusted markup in text expressions. Native templates can use `$context->encode()`, `fragment()`, and `attributes()` to produce HTML. |
| Caching | `TemplateCache::remember()` caches parsed templates by path and file modification time. Use `clear()` to invalidate the cache explicitly. Adjacent `.atom.html` component templates use a cache automatically. |
| Icons | Register a resolver by prefix with `IconRegistry`. Implement `IconResolverInterface` to supply icons and `IconProviderInterface` to register a collection. |
| Errors | Catch `ViewParseException` for template syntax errors, `ViewRenderException` for View rendering errors, and `ComponentRegistryException` for registration and lookup errors. |

### Template behavior

- Write PHP expressions inside `{{ ... }}` for text and attribute interpolation. Use `:name="..."` for typed attribute values and `{{ $attributes }}` in a tag to spread attributes. Later attributes take precedence.
- Use conditionals and loops in `.atom.html` templates with `@if` and `@foreach`, or with the corresponding `:if` and `:foreach` attributes.
- Ordinary text and attribute interpolation escapes HTML metacharacters. `null` renders as empty text. For HTML attributes, `false` and `null` omit the attribute, `true` emits a valueless attribute, and an empty string emits an explicit empty value.
- Treat `.atom.html` and `.atom.php` sources as trusted application code. The default expression evaluator executes PHP. Pass external input through render variables.

### Framework integration

Framework supplies a component factory through `ComponentFactoryInterface` and wires `ExpressionEvaluatorInterface`, `ComponentHydrator`, cache and profiling hooks in `Templates`, and style discovery and composition. Components uses `StructuralComponentInterface`, `StructuralComponentContext`, and AST nodes for components that control how their children render.

These integration hooks evolve together with their monorepo callers. Tokenizer output, AST node layout, and the delimiter scanner are implementation details. For ordinary rendering, pass parsed templates directly from the parser to the renderer.
