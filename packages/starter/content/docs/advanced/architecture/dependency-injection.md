---
title: Dependency injection
description: Resolve application collaborators explicitly.
order: 63
---

# Dependency injection

Constructor injection makes collaborators visible and allows services to be scoped to the application or request lifecycle.

```php
public function __construct(private ArticleRepository $articles)
{
}
```
