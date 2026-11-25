# Atom Framework

Core framework package for Atom applications.

The public distribution is generated at [atom-fm/framework](https://github.com/atom-fm/framework). Changes belong in [atom-fm/atom](https://github.com/atom-fm/atom).

The package is developed in the Atom monorepo. Its public API includes Pages, the `.atom.html` template engine, routing, dependency injection, HTTP foundations, database support, and optional feature modules.

From this directory:

```powershell
composer install
composer check
```

## Benchmarks

From this directory, run the PHPBench suite after `composer install`.

```shell
```

The fixture is a catalogue page with 12 items, interpolated attributes, and conditional markup.
The suite measures parsing the template source, rendering the parsed template, and handling
a GET through an initialized application with a warm template cache. The request benchmark
runs in process and excludes the web server and network.

Compare measurements on the same machine with the same PHP version and settings. Each
benchmark runs in batches across multiple samples. Check the variation before drawing
conclusions from small timing differences.

Repository-wide documentation and runnable applications live at the monorepo root.
