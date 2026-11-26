---
title: Commands
description: Common commands available in the starter.
order: 41
---

# Commands

| Command | Purpose |
| --- | --- |
| `php atom serve` | Start the local development server on `127.0.0.1:8020` |
| `php atom migrate` | Apply database migrations |
| `php atom docs:index` | Rebuild documentation metadata |
| `php atom accounts:user:create` | Create a local account |

Pass `--host` or `--port` to change the development address:

```bash
php atom serve --host=0.0.0.0 --port=8080
```

The built-in server is for local development only.
