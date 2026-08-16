# Packages

This directory contains independently installable Composer package sources.

- `framework/` is the core `atom/framework` library.
- `starter/` is the `atom/starter` project template.

New directories belong here only when they need an independent Composer identity and release lifecycle. Framework feature modules remain under `framework/src/Modules` until that need is concrete.

Published repositories can be generated from these directories without changing the monorepo development layout.
