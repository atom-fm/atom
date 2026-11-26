---
title: Routes
description: Documentation URL and file mapping rules.
order: 45
---

# Routes

`content/docs/reference/routes.md` maps to `/docs/reference/routes`. A folder `index.md` maps to the folder URL itself.

Use only one source file per URL. For example, `guides.md` and `guides/index.md` both map to `/docs/guides`. `php atom docs:index` reports both files and leaves the existing index intact if it finds a collision.

Each folder containing nested documents needs a parent page: either a folder `index.md` or a sibling Markdown file with the folder's name. For example, `guides/forms/contact.md` needs pages for both `/docs/guides` and `/docs/guides/forms`. The index command reports missing parents rather than publishing documents that would be absent from the sidebar and reading order.
