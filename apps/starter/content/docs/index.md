---
title: Documentation
description: Build clear, fast documentation with Markdown and Atom.
order: 1
---

# Documentation

The starter treats documentation as content. Add Markdown files to `content/docs` and they become server-rendered pages with automatic navigation.

## Start writing

Each document may provide a small YAML header:

```yaml
---
title: Getting started
description: Install the application and create your first page.
order: 10
---
```

Raw HTML is removed and unsafe links are rejected. Tables, task lists, strikethrough, and fenced code blocks use GitHub-flavored Markdown.

## Organize content

Directories map directly to URLs. For example, `content/docs/guides/pages.md` is available at `/docs/guides/pages`.
