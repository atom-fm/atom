---
title: Add navigation
description: Create links enhanced by MorphDOM.
order: 32
---

# Add navigation

Add `atom:navigate` to internal links that should update the main document region progressively.

```html
<a href="/about" atom:navigate>About</a>
```

## Add a page to the main menu

The Starter's shared menu lives in `app/Components/SiteLayout.atom.html`. Once you have a page at `/counter`, add this link immediately after **Home** inside the navigation element labelled `Primary navigation`. For a different page, use its route path and title.

```html
<a href="/counter" atom:navigate>Counter</a>
```

Open `/` and select **Counter** in the menu. The address changes to `/counter` and the page appears inside the layout's `<main atom:update-root>`. The shared header stays in place. Use the browser's Back button to return to the homepage.
