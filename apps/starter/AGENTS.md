# Atom Starter Agent Guide

This file applies to the entire application. Keep it when using an AI coding agent: it describes both how the site works and the visual standard expected from changes.

## Product Brief

This starter is a foundation for polished presentation and documentation websites. It should feel authored for the project, not like a generic framework demo.

Before changing the interface, learn the site's subject, audience, voice, and existing content. If the project does not yet have a visual direction, establish one in a few concrete words and apply it consistently. The starter's default direction is:

> Quiet editorial design: warm neutral surfaces, compact sans-serif type, strong dark headings, restrained green accents, minimal borders, generous but deliberate space, and dense readable navigation.

Replace that sentence when the site develops its own identity. A clear art direction helps future agents make coherent decisions.

## Design Standard

- Make the first screen communicate what the site is and why it matters.
- Build a clear hierarchy with type, spacing, and composition before adding decoration.
- Use a small type scale and spacing rhythm consistently. Large display type is appropriate only where it supports the message.
- Keep body copy comfortably readable: usually 60-75 characters per line with generous line height.
- Use the accent color selectively for actions, current state, and meaningful emphasis.
- Prefer a few strong compositions over many interchangeable cards.
- Let each major page have a recognizable focal point; do not repeat the same hero and card grid everywhere.
- Use real project language. Avoid generic copy such as "unlock your potential", "seamless solutions", and feature filler.
- Preserve visible keyboard focus, sufficient color contrast, semantic landmarks, and useful labels.
- Design narrow screens intentionally. Recompose layouts instead of merely shrinking desktop dimensions.
- Respect `prefers-reduced-motion`. Motion should clarify navigation or state, never delay access to content.

Avoid the common generated-site look: arbitrary gradients, purple glow, glass panels, excessive pills, rounded cards around every paragraph, decorative statistics without meaning, and animation without purpose. These are not forbidden when the site's identity calls for them; they should be conscious choices rather than defaults.

## Start With the Existing System

Inspect the current page in a browser and read the nearby PHP, template, and CSS files before editing. Extend the established visual language unless the task explicitly calls for a redesign.

The CSS entry point is `public/style.css`. It imports these app-owned layers in order:

```text
public/css/theme.css   Design tokens: colors, typography, spacing, radii, and layout dimensions
public/css/base.css    Normalization and shared element defaults
public/css/ui.css      Reusable application UI components and their responsive behavior
public/css/site.css    Site shell, header, navigation, and footer
public/css/pages.css   Genuine one-page composition exceptions
public/css/docs.css    Documentation layout and prose
public/css/design.css  The `/design` token catalogue and specimen layout
```

Begin branding work in `theme.css`. Reuse its semantic custom properties instead of scattering literal colors. Add site-specific components to an appropriate focused stylesheet; split a new stylesheet only when it represents a real visual area, not for every component.

The live design-system catalogue is available at `/design`. It renders the actual color, typography, spacing, and component tokens used by the starter. When adding or changing a reusable token, update the catalogue in `app/Pages/DesignPage.atom.html` so the page remains an accurate review surface. The current font-size scale runs from `--atom-font-size-xs` through `--atom-font-size-display`; use these properties instead of embedding new heading sizes in component CSS.

Static CSS URLs currently use a shared query-string version to prevent Apache and browsers from mixing stale imported files. When changing CSS, increment the same version in all three places:

```text
public/style.css                         Imported CSS URLs
app/Components/SiteLayout.atom.html     Main site stylesheet URL
app/Application.php                     Accounts themeStylesheet option
```

There is no frontend build step. Prefer modern, readable CSS and semantic HTML over adding a CSS framework. Add a client dependency only when it provides substantial behavior that the server and a small amount of local code cannot reasonably provide.

## Current UI Components

The starter owns a deliberately small set of high-level presentation components under `app/Components`:

```text
PageHero       Display or compact page opening, with optional action content
SectionIntro   Eyebrow and section-heading relationship
FeatureGrid    Responsive two- or three-column feature composition
Feature        Marker, heading, and rich description content
SiteLayout     Document shell, primary navigation, authentication state, update root, and footer
```

These components are registered in `app/Application.php`. Prefer them when they express the page's content hierarchy; do not wrap every HTML element in a component. `SiteLayout` intentionally keeps the one-off navbar and footer inline until either develops reusable variants or meaningful independent behavior.

Keep these components application-owned while Atom, CX Lang, and Zee Chess establish real reuse patterns. Promote only a smaller proven primitive into the framework, not a site's branded composition.

## Application Model

Atom is a PHP 8.4, server-rendered framework. JavaScript and MorphDOM enhance a complete working site; they are not the source of business state.

```text
app/Application.php              Services, modules, and page registration
app/Pages/                       Page classes and adjacent `.atom.html` templates
app/Components/                  Site layout and high-level presentation components
app/Documentation/               Markdown rendering and navigation metadata
app/Providers/                   Application service providers
app/Database/Migrations/         Ordered database migrations
content/docs/                    Markdown documentation content
public/                          Web entry points and directly served assets
storage/docs-index.json          Generated documentation navigation metadata
```

- Browser screens use Pages. Keep request behavior and state in the Page class and markup in its adjacent `.atom.html` template.
- Extend `App\Pages\AppPage` for ordinary site pages so metadata and the shared layout remain consistent.
- Register pages through the existing `app/Pages` directory discovery. Use `#[PageRoute]` for routes.
- Keep templates declarative and close to ordinary HTML. Escape user-controlled content; use raw HTML only for content that has already been deliberately sanitized.
- Put shared site chrome in `SiteLayout`; keep one-page compositions local to that page.
- Keep brand-specific components in this application. Promote something into Atom only after it solves a recurring framework-level need.
- Use strict types, typed properties and parameters, and small explicit APIs in PHP.

## MorphDOM Navigation

The layout returns a complete HTML document on every request. `<main atom:update-root>` marks the region MorphDOM replaces during enhanced navigation.

- Add `atom:navigate` to same-site GET links that should update the main content without a full page load.
- Leave links usable as normal links. Opening in a new tab, direct visits, refreshes, and disabled JavaScript must still work.
- Do not add `atom:navigate` to external links, downloads, or flows that require a full document lifecycle unless their behavior has been verified.
- Keep authentication and mutations server-owned. Use page actions or ordinary forms rather than client-side replicas of state.
- After navigation, confirm the URL, title, main content, current navigation state, focus behavior, back/forward history, and signed-in header all remain correct.
- Do not widen the update root merely to hide stale shared UI. Shared state that can change across requests must be deliberately refreshed or placed inside the appropriate update boundary.

## Documentation Sites

Documentation pages live in `content/docs`. Nested folders become nested routes and sidebar groups. Each document may use YAML front matter:

```yaml
---
title: Pages
description: Build a server-rendered page.
order: 20
---
```

- Write for scanning: a clear opening, descriptive headings, short paragraphs, and focused examples.
- Prefer working examples over abstract claims. Include language identifiers on fenced code blocks for server-side syntax highlighting.
- Use a folder's `index.md` to name and order that navigation section.
- Use one Markdown source per documentation URL. For example, `guides.md` and `guides/index.md` collide; `docs:index` reports both paths and preserves the last valid index.
- Every nested documentation folder needs a parent page (`folder/index.md` or `folder.md`). Indexing rejects missing parents so documents cannot silently disappear from the sidebar and reading order. The diagnostic names the affected file and the parent page to add.
- Run `php atom docs:index` after adding, moving, deleting, or changing front matter. This updates navigation metadata only; Markdown is rendered on request.
- Keep documentation navigation useful at 100+ pages: concise titles, shallow categories where possible, and stable ordering.
- Raw Markdown HTML is intentionally stripped. Do not weaken content safety to achieve a visual effect.
- Sidebar branch titles are normal navigation links followed by separate native `details`/`summary` disclosures. Never place links or buttons inside `summary`. Keep a descriptive disclosure name, `aria-current="page"` on the selected link, and open ancestors of the current document.
- The mobile sidebar checkbox must remain keyboard focusable at narrow widths; reflect its focus on the visible label. Keep it hidden from keyboard navigation on desktop. Navigation must work without JavaScript as well as with MorphDOM.
- Run `php tests/documentation-navigation.php` after changing sidebar rendering. This regression check covers separate controls, current-page semantics, nested expansion, safe escaping, and enhanced links.
- Previous/next links follow the sidebar's depth-first reading order, not the flat metadata index. Keep sibling ordering by `order` then slug; a section's descendants precede the next sibling section. Run `php tests/documentation-reading-order.php` after changing pagination or hierarchy behavior.

### Starter Improvement Progress

- 2026-10-23: Fixed documentation sidebar accessibility: independent section links and native disclosures, current-page semantics, focus indicators, and a keyboard-reachable mobile toggle. Checked desktop and narrow layouts in Opera, Space-key disclosure/menu operation, and navigation between documentation pages. The next review priorities are typography-token consistency and design-catalogue accuracy.
- 2026-10-26: Fixed fresh-install documentation indexing: `docs:index` now creates a missing storage directory, and setup instructions include generating navigation before serving the site. Run `php tests/documentation-index.php` to check missing storage, index round trips, and regeneration without touching the local index. Typography-token consistency and design-catalogue accuracy remain next priorities.
- 2026-11-02: Aligned documentation previous/next links with sidebar reading order, including nested sections and reused order numbers. Added isolated regression coverage for interleaved index entries, section boundaries, first/last pages, and unindexed documents; clarified authoring guidance. Typography-token consistency and design-catalogue accuracy remain open.
- 2026-11-09: Made docs indexing reject duplicate URLs before replacing navigation metadata. The index regression check covers the collision diagnostic and preservation of the last valid index. Typography-token consistency and design-catalogue accuracy remain open.
- 2026-11-16: Added missing-parent validation to docs indexing, preventing nested pages from silently disappearing from navigation. Regression checks cover a nested missing parent, preservation of the valid index, and recovery with a folder index under a sibling-file parent. Typography-token consistency and design-catalogue accuracy remain open.

## Accounts and Data

The starter uses SQLite and includes Atom's Accounts module. Account pages consume the same design tokens through `/style.css`, so theme changes should also be checked on `/account/login`.

- Create schema changes as ordered migrations under `app/Database/Migrations`.
- Never commit real credentials, local databases, session data, cache entries, or secrets.
- Keep authorization and identity decisions on the server.
- Treat example login details as local development data, not production defaults.

## Working Method

When asked to build or redesign a page:

1. Inspect the existing route, content, neighboring pages, shared layout, and relevant CSS.
2. State or infer a compact visual direction appropriate to the site's subject.
3. Decide the page's content hierarchy before styling it.
4. Reuse tokens and shared shell patterns, then add the smallest page-specific composition needed.
5. Render the actual page and inspect it at desktop and mobile widths.
6. Exercise enhanced navigation from another page and direct loading of the new URL.
7. Refine typography, spacing, wrapping, hover, focus, empty states, and awkward intermediate widths.

Do not call a page finished based only on source code. Visual work requires browser inspection.

## Verification

Typical local setup:

```powershell
composer install
copy .env.example .env
php atom migrate
php atom accounts:user:create admin@example.com change-me "Admin User"
php atom docs:index
php atom serve
```

For an Apache virtual host such as `http://atom.test`, set the document root to `apps/starter/public` and route requests through `index.php`. The portable local workflow is `php atom serve`.

Before handing off a change, run checks proportional to it:

```powershell
php -l app\Path\To\ChangedFile.php
php atom docs:index
composer validate --strict
```

Also check `git diff --check`. For interface changes, inspect the relevant routes in a real browser at wide and narrow viewports, test keyboard focus, and verify both MorphDOM navigation and direct requests. Report what was actually checked.

## Change Discipline

- Preserve user changes already present in the worktree.
- Keep work scoped to the requested site and avoid speculative framework abstractions.
- Do not replace the template system with a client framework to implement ordinary presentation pages.
- Do not add a build pipeline merely for convenience.
- Do not publish, deploy, push, or create releases unless explicitly requested.
- Update this guide when the project's architecture or visual direction materially changes.
