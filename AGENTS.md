# Atom Framework Agent Guide

This file applies to the entire repository.

## Project Direction

Atom is a compact PHP 8.4 framework designed primarily for traditional request lifecycles such as PHP-FPM and mod_php.

Keep these principles intact:

- Prefer a small, explicit API over broad abstraction layers.
- Browser-facing application screens use Pages; API endpoints use controllers.
- Server-rendered `.atom.html` templates and page actions are the primary UI model.
- Client JavaScript and MorphDOM are progressive enhancements, not the source of business state.
- Optimize only when a measured problem exists. Do not introduce speculative template or application caching.
- Add framework primitives only when they solve recurring application needs.
- Treat modules as cohesive features such as Accounts, API Explorer, Error Pages, or development tooling.
- Keep browser runtime support in `Modules/Client` and reusable UI primitives in `packages/components`.
- Current work should favor component polish, coherent examples, CSS consistency, tests, and documentation over large new subsystems.

## Repository Layout

```text
packages/framework/  Core Composer package, source, and framework tests
packages/components/ Server-rendered widgets, component assets, and component tests
packages/sql/        Standalone SQL query builder and dialect compilers
packages/orm/        Standalone PDO persistence, mapping, and model APIs
packages/validation/ Standalone PHP validation rules, schemas, and results
packages/view/       Standalone template parsing and component rendering
packages/di/         Standalone constructor injection and service providers
packages/profiler/   Standalone timing spans and summaries
apps/starter/    Composer-installable starter application source
apps/sample/         Sample application and API
apps/showcase/       Interactive component catalogue
docs/                Repository-wide framework documentation
```

Important module locations:

```text
packages/framework/src/Modules/Client/       Atom browser runtime and MorphDOM adapter
packages/components/src/                   UI components and their CSS and JavaScript
packages/view/src/Icons/                    Shared icon resolution contracts
packages/lucide/                             Optional Lucide icon package and SVG catalogue
packages/framework/src/Modules/Accounts/     Account and identity feature module
packages/framework/src/Modules/ApiExplorer/  API Explorer feature module
packages/framework/src/Modules/ErrorPages/   Default error rendering
```

## Working Safely

- The worktree may contain substantial user changes. Preserve them and avoid unrelated rewrites.
- Inspect `git status --short` before broad edits.
- Never discard, reset, or overwrite existing changes to make a task easier.
- Keep changes scoped to the requested slice.
- Do not publish packages, create releases, or push changes unless explicitly requested.
- Use predictable paths and existing conventions instead of adding application-level configuration without a concrete need.

## PHP Conventions

- Target PHP 8.4 and begin PHP files with `declare(strict_types=1);`.
- Follow the existing PSR-4 layout and namespace structure.
- Use typed properties, parameters, and return values.
- Prefer `final` for concrete classes and `readonly` for immutable value/configuration objects when appropriate.
- Use PHP attributes for routes, page actions, state, hydration, and metadata where the framework already provides them.
- Use double-quoted strings consistently with surrounding framework code.
- Keep public APIs small. Avoid adding aliases or compatibility layers when there are no external consumers.
- Migration filenames use stable ordered names such as `M0001_create_users_table.php`.

## Pages and Templates

- Page classes live beside their `.atom.html` templates.
- Browser behavior belongs in the Page class; markup belongs in the adjacent template.
- Use `#[PageRoute]`, `#[PageAction]`, and `#[State]` rather than custom routing or client state.
- Keep templates declarative. Prefer components over conditional AST plumbing when a reusable component expresses the intent.
- Use plain `.atom.php` templates only where native PHP templates are already the intended convention. Framework error views use `.atom.html` and module-local components.

## Components

- Small components normally implement `ComponentInterface` and render with `Html` helpers.
- Larger markup-oriented components may extend `TemplateComponent` and use an adjacent `.atom.html` file.
- Forward arbitrary HTML attributes through `AttributeBag`.
- Use named fragments such as `Component.Actions` for structured composition.
- Register bundled components in `Components::definitions()` in `packages/components`.
- Keep application and module-local components local unless they are intentionally exported.
- Preserve component registry scoping: module registries are private, parent-aware, and sealed after registration.
- Use the optional `atom-fm/lucide` package in the showcase. Keep application-specific icons local to their application.
- CSS classes owned by the framework use the `atom-` prefix and framework token variables.
- Include keyboard focus treatment and `prefers-reduced-motion` behavior for interactive CSS.

When changing component CSS:

- Update `Components::STYLES_VERSION`.
- Update the matching asset-version assertion.
- Check responsive behavior and reduced-motion behavior.

## Client Runtime

- Client resources are source files served directly; there is no build step.
- Keep the current broadly compatible `var`/function JavaScript style unless the runtime is deliberately modernized as a separate change.
- Business logic must remain on the server.
- Generic DOM lifecycle behavior belongs in the Client/MorphDOM adapter, while visual behavior belongs in CSS.
- `atom:transition` is the opt-in boundary for deferred MorphDOM removal and CSS exit animations.

When changing client assets:

- Update the relevant version constant in `Atom\Modules\Client\Client`.
- Use `Components::SCRIPTS_VERSION` for JavaScript owned by the Components package.
- Update asset-version tests.
- Run `node --check` on edited JavaScript.

## Showcase Guidelines

- The showcase is a working catalogue, not a second framework implementation.
- Use the local `ShowcasePageHeader` for component catalogue pages.
- Keep examples concise and focused on one behavior.
- Each component page should be self-contained. The main sidebar handles discovery.
- Do not add “related component” links inside examples.
- Links required to demonstrate Buttons, Tabs, Pagination, Breadcrumbs, Cards, or navigation components should remain on the same showcase page.
- Prefer realistic composed examples after the primitive examples.
- Avoid placeholder wording once a component has a working example.
- Reuse framework components and the showcase's Lucide package rather than showcase-only copies of ordinary icons.

## Modules

- Framework primitives such as HTTP, sessions, cache, queues, mail, routing, and security live in their foundation namespaces.
- Feature bundles belong under `packages/framework/src/Modules` until they have a concrete reason to become independent packages.
- A module may register routes, pages, commands, jobs, resources, services, and private component sets through `ModuleContext`.
- Export components only when the module is intended to provide them to the application.
- Keep module commands thin; reusable publishing behavior belongs in the framework Publisher/PublishBundle foundation.

## Verification

From the repository root:

```powershell
composer check
```

This runs PHPStan and PHPUnit for SQL, ORM, Validation, View, DI, Profiler, Framework, and Components, tests Lucide, and validates the Composer manifests.

Useful targeted checks:

```powershell
php packages/framework/vendor/bin/phpunit --configuration packages/framework/phpunit.xml --filter TestName
php -l path\to\File.php
node --check packages\framework\src\Modules\Client\Resources\atom-morphdom.js
git diff --check
```

For framework changes:

- Add or update focused PHPUnit tests.
- Prefer testing observable output and request lifecycles over implementation details.
- Run PHPStan and the full PHPUnit suite before handoff.

For showcase changes:

- Exercise affected routes through `Showcase\Application::handle()` with real `Atom\Http\Request` instances.
- Test relevant GET and POST/page-action paths, not only template compilation.
- If behavior depends on DOM updates, animation, focus, or browser layout, verify it in a real browser when practical.

## Running the Applications

Sample API and API Explorer:

```powershell
cd apps/sample
composer install
copy .env.example .env
php atom migrate:fresh
php atom db:seed
php -S 127.0.0.1:8021 -t public public/server.php
```

Component showcase:

```powershell
cd apps/showcase
composer install
php -S 127.0.0.1:8022 -t public
```

## Documentation

- Update the relevant file in `docs/` when adding or changing a public contract.
- Keep README instructions focused on installation and running the sample applications.
- Describe current behavior rather than planned features.
- Do not duplicate long implementation explanations across multiple documentation files.
