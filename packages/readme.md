# Packages

This directory contains independently installable Composer package sources.

- `sql/` is the standalone `atom-fm/sql` query builder and dialect compilers.
- `orm/` is the standalone `atom-fm/orm` mapping and persistence library built on SQL and PDO.
- `validation/` is the standalone `atom-fm/validation` library for schemas, rules, and attribute validation.
- `framework/` is the core `atom-fm/framework` library.
- `components/` is the optional `atom-fm/components` widget library and its CSS and JavaScript.
- `lucide/` is the optional `atom-fm/lucide` icon resolver and SVG catalogue.
- `starter/` is the `atom-fm/starter` project template.

New directories belong here only when they need an independent Composer identity and release lifecycle. Framework feature modules remain under `framework/src/Modules` until that need is concrete.

Published repositories can be generated from these directories without changing the monorepo development layout.
