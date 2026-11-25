---
title: Getting started
description: Add and organize Markdown documentation in the Atom starter.
order: 10
---

# Getting started

Create a Markdown file under `content/docs`. Its path becomes its public documentation URL.

## Add a document

```text
content/docs/concepts/pages.md
```

The file above is served at `/docs/concepts/pages`. Within each folder, navigation is ordered first by the `order` front-matter value and then by URL slug. Previous and next links follow the sidebar from top to bottom, visiting a section's children before the next section. Child pages do not need globally unique order numbers.

## Link between pages

Use normal site-relative Markdown links:

```markdown
[Read about templates](/docs/concepts/templates)
```

Atom progressively enhances those links when `atom:navigate` is present in the page shell, while every document remains usable without JavaScript.

## Server-side highlighting

Add a language after the opening fence. Highlighting is rendered in PHP, so code is complete before it reaches the browser:

```php
<?php

use Atom\Page\PageRoute;

#[PageRoute("/hello")]
final class HelloPage extends AppPage
{
    public string $title = "Hello, Atom";
}
```

Unknown or unlabelled languages remain readable as plain text.
