# Page actions

[Atom Framework](Index.md)

A page action is a PHP method marked with `#[PageAction]`. A button or form names the method to run. Atom sends the request, restores the page state, calls the method, and renders the updated HTML.

## How a page action works

1. a `GET` request renders a page
2. the page template includes an `atom-state` meta value for `#[State]` properties
3. the user clicks an `atom:action` element or submits an enhanced form
4. Atom restores page state
5. Atom hydrates page inputs
6. Atom invokes the `#[PageAction]` method
7. Atom renders the page again
8. the browser updates the configured update root

The server can keep returning complete HTML. The client decides how to apply it.

## Define an action

Build a counter whose step can change between clicks. In a fresh Atom Starter app, save the page class as `app/Pages/CounterPage.php`. Mark both numbers with `#[State]` so Atom restores them before each action runs.

```php
<?php

declare(strict_types=1);

namespace App\Pages;

use Atom\Page\PageAction;
use Atom\Page\PageRoute;
use Atom\Page\State;

#[PageRoute("/counter")]
final class CounterPage extends AppPage
{
    public string $title = "Counter";

    #[State]
    public int $count = 0;

    #[State]
    public int $step = 1;

    #[PageAction]
    public function increment(): void
    {
        $this->count += $this->step;
    }

    #[PageAction]
    public function setStep(int $step): void
    {
        $this->step = max(1, min(10, $step));
    }

    #[PageAction]
    public function reset(): void
    {
        $this->count = 0;
    }
}
```

Save the template beside it as `app/Pages/CounterPage.atom.html`.

```html
<h1>Counter</h1>
<p aria-live="polite">Count: {{ $this->count }}</p>
<button type="button" atom:action="increment">Add {{ $this->step }}</button>
<button type="button" atom:action="setStep(5)">Use step 5</button>

<form method="post" atom:submit="setStep">
    <label>Step <input type="number" name="step" min="1" max="10" required value="{{ $this->step }}"></label>
    <button type="submit">Apply step</button>
    <button type="submit" formnovalidate atom:action="reset">Reset count</button>
</form>
```

Run `php atom serve` from the project root and open `/counter`. Then try the controls.

1. Click **Use step 5**, then **Add 5**. The count becomes `5`. Here the button supplies the argument to `setStep(5)`.
2. Enter `3` in **Step** and click **Apply step**. The form supplies the named `step` field to the same PHP method. The button now reads **Add 3**. Click it to reach `8`.
3. Click **Reset count**. Its `atom:action` overrides the form's `atom:submit`, so the count returns to `0` and the applied step stays `3`.

Enhanced forms use native browser validation. A submit button can bypass it with `formnovalidate`, as **Reset count** does above. The selected submit button's `atom:action` takes precedence even when `<Form submit="…">` generated a hidden `_action` field. Atom allows one pending submission per form, including submissions made with Enter or `requestSubmit()`.

Action parameters can come from:

- action arguments, such as `setStep(5)`
- route parameters
- body fields
- query parameters
- explicit hydration attributes like `#[FromBody]`, `#[FromRoute]`, and `#[FromQuery]`

## Enhanced Navigation

Add `atom:navigate` to a same-origin link or GET form to load the destination in the current page.

```html
<a href="/articles" atom:navigate>Articles</a>
```

Atom fetches the target page, updates the DOM and scrolls the window and main content to the top. A fragment such as `/articles#comments` takes you to the matching anchor. Back and Forward restore the reading position of each history entry. Page actions keep the current position, including when they update the query string.

Use `atom:preserve-scroll` on a filter form and its clear link to keep the list in view. The form sends its named controls and the selected submit button in the query string. On links and GET forms, `atom:preserve-state` independently carries server page state in the request body while keeping the destination URL bookmarkable.

For same-page navigation with `atom:preserve-state`, add the attribute to a separate editor form with an `id` to keep its unsaved input, textarea, and select values in the browser. Give each field a stable `id`. Atom restores values when the destination renders the same form and fields.

```html
<form action="/articles" method="get" atom:navigate atom:preserve-scroll>
    <label>Search <input name="query"></label>
    <button type="submit">Filter</button>
    <a href="/articles" atom:navigate atom:preserve-scroll>Clear filters</a>
</form>
<Pagination query-key="page" navigate preserve-scroll />
```

## Update Root

Use `atom:update-root` to keep stable layout chrome out of the update.

```html
<body>
    <header>
        <a href="/" atom:navigate>Home</a>
    </header>

    <main atom:update-root>
        <?= $context->fragment($component->content) ?>
    </main>
</body>
```

If both the current page and the returned page contain `atom:update-root`, only that element is updated.
If either side is missing it, Atom falls back to updating `body`.

## Client Scripts

`atom.js` provides the core runtime:

- `atom:navigate`
- `atom:action`
- `atom:submit`
- `atom:transition`
- `atom:update-root`
- page state submission
- `Atom.update`
- `Atom.setUpdateEngine(...)`

The default update engine replaces the update root content and preserves common browser state such as focus, selection, and scroll. Both built-in engines display the server-rendered value when the focused field belongs to the submitted form. During navigation, including Back and Forward, fields in an `atom:navigate` form reflect the destination page. Other updates preserve the focused field's value.

For smoother updates, load the optional morphdom adapter:

```html
<script src="/atom/client/resources/atom.js"></script>
<script src="/atom/client/resources/morphdom.js"></script>
<script src="/atom/client/resources/atom-morphdom.js"></script>
```

The adapter plugs into the same facade:

```js
Atom.setUpdateEngine({
    update(current, next, options) {
        // custom update engine
    }
});
```

When `options?.preserveFocusedValue` is `false`, apply the server-rendered value to the focused field.

## Exit Transitions

Add `atom:transition` to an element when the MorphDOM adapter should defer its removal for a CSS exit animation:

```html
<div class="app-overlay" atom:transition>...</div>
```

When the next server response omits the element, the adapter adds `data-atom-leaving`, makes the element inert and hidden from assistive technology, and waits for its finite CSS animations before removing it:

```css
.app-overlay[data-atom-leaving] {
    animation: app-overlay-out 160ms ease-in both;
    pointer-events: none;
}
```

Put the attribute on the outer lifecycle boundary only. Animations on its descendants are included automatically. A timeout prevents cancelled or stalled animations from leaving stale elements behind, and a rapid update that restores the element cancels its pending removal. Without the MorphDOM adapter, removal remains immediate.

## Design Rule

Keep business logic in the PHP page class. Use `atom:*` attributes to bind controls to page actions and specify which HTML to update.

```html
<button atom:action="delete(12)">Delete</button>
```

The action name maps to a server method.

Validate submitted values in the page action before changing application data.
