# Atom Starter App

A minimal Atom application using SQLite and the Accounts login module.

The public distribution is generated at [atom-fm/starter](https://github.com/atom-fm/starter). Changes belong in [atom-fm/atom](https://github.com/atom-fm/atom).

The included `AGENTS.md` gives AI coding agents the application's architecture, visual direction, MorphDOM conventions, and browser-quality checklist. Keep it with the project and adapt its product brief as the site develops its own identity.

## Install with Composer

```powershell
composer create-project atom-fm/starter my-app
cd my-app
```

For an existing checkout, run `composer install` from the project directory. Then configure the app and start the server.

```powershell
copy .env.example .env
php atom migrate
php atom accounts:user:create admin@example.com change-me "Admin User"
php atom docs:index
php atom serve
```

Open `http://127.0.0.1:8020/account/login` and sign in with the user created above.

The documentation area is available at `/docs`. Generate its navigation index with `php atom docs:index` before the first visit, including on fresh deployments. The command creates `storage/` if needed and can be run again without affecting application data.

The development server defaults to `127.0.0.1:8020`. Override either value with `php atom serve --host=0.0.0.0 --port=8080`. PHP's built-in server is intended only for local development.

## Markdown documentation

The starter includes a Markdown-driven documentation area at `/docs`. Add `.md` files under `content/docs`; nested paths become nested URLs automatically. YAML front matter supports `title`, `description`, and `order`:

```markdown
---
title: Pages
description: Build server-rendered application pages.
order: 20
---

# Pages
```

Documentation uses GitHub-flavored Markdown with raw HTML stripped and unsafe links disabled. The sidebar and previous/next navigation are generated from the content directory.

Run `php atom docs:index` after adding, moving, or changing the front matter of documentation files. The generated index contains only navigation metadata; each requested Markdown document is still rendered on demand. Use an `index.md` inside a folder to give that section a navigable title, description, and order. Sidebar indentation follows the directory depth and supports arbitrary nesting.

Order values apply among siblings in the same folder; ties use the URL slug. Previous and next links follow the sidebar hierarchy, visiting each section's children before moving to the next section. Order numbers can be reused across folders.

Fenced code blocks are highlighted on the server with Phiki. Add a supported language after the opening fence, such as `php`, `js`, `html`, `css`, `json`, `bash`, or `yaml`. Projects can register application-owned TextMate grammars for custom languages without changing Atom.

## CSS

`public/style.css` is the stable stylesheet entry point used by the site and Accounts. It imports focused source files from `public/css` in cascade order:

```text
theme.css  Semantic colors and typography
base.css   Normalization and shared element defaults
site.css   Header, navigation, content shell, and footer
pages.css  Starter home and presentation pages
docs.css   Documentation layout and prose
```

Change `theme.css` to tune site colors. A project that does not need the presentation or documentation examples can remove the corresponding import.
