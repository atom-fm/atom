---
title: Templates
description: Render declarative Atom templates on the server.
order: 21
---

# Templates

Atom templates live beside their page or component class and use the `.atom.html` extension.

## Expressions

Expressions are escaped by default:

```html
<h1>{{ $this->title }}</h1>
```

Use components for reusable markup and keep business state in the page class.
