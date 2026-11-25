# Atom Framework

Core framework package for Atom applications.

The public distribution is generated at [atom-fm/framework](https://github.com/atom-fm/framework). Changes belong in [atom-fm/atom](https://github.com/atom-fm/atom).

The package is developed in the Atom monorepo. Its public API includes Pages, the `.atom.html` template engine, routing, dependency injection, HTTP foundations, database support, and optional feature modules.

## Install

Add the stable framework to a PHP 8.4 or later project.

```bash
composer require atom-fm/framework
```

Composer also installs `atom-fm/sql` and `atom-fm/orm`. For a complete application, create a project with Atom Starter.

```bash
composer create-project atom-fm/starter my-app
```

Follow the [Getting started guide](https://atom-framework.dev/docs/getting-started) to configure and run your app.

## Develop in the monorepo

Run these commands from `packages/framework` in the Atom monorepo.

```powershell
composer install
composer check
```

## Benchmarks

From `packages/framework` in the monorepo, run the PHPBench suite after `composer install`.

```bash
php vendor/bin/phpbench run
```

The fixture is a catalogue page with 12 items, interpolated attributes, and conditional markup.
The suite measures parsing the template source, rendering the parsed template, and handling
a GET through an initialized application with a warm template cache. The request benchmark
runs in process and excludes the web server and network.

Compare measurements on the same machine with the same PHP version and settings. Each
benchmark runs in batches across multiple samples. Check the variation before drawing
conclusions from small timing differences.

Repository-wide documentation and runnable applications live at the monorepo root.
