---
title: Pages
description: Browser-facing request handlers in Atom.
order: 22
---

# Pages

Pages combine a route, server-side state, actions, and an adjacent template.

## Define a route

```php
#[PageRoute("/articles")]
final class ArticlesPage extends AppPage
{
}
```
