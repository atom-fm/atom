# Light DOM components

Active standalone development now lives in [the sibling WebComponents project](../../WebComponents/README.md), a self-contained npm package with its own Node demo server and browser tests. The PHP Components package contains the earlier browser controls described below. Framework adoption will follow once the standalone API is validated.

The browser controls in `atom-fm/components` use native JavaScript classes and the framework's morphdom runtime. There is no compiler, bundler, Shadow DOM, or virtual DOM. Local state is for control interaction; application business state remains on the server.

Register the Client and Components modules in the application. In the layout, load `<ClientScripts morphdom />` followed by `<ComponentsScripts />`. The Components script registers `atom-lookup` and `atom-datepicker`. The page adapter updates component attributes while preserving their owned children. Component resources use the Components module's configured path.

The local demo includes example CSS. Use prefixed classes and application CSS to style controls.

From the repository root:

```powershell
composer --working-dir=packages/components install
php -S 127.0.0.1:8023 -t packages/components
```

Open `http://127.0.0.1:8023/tests/Client/fixtures/components-demo.html`.

## Author a component

```js
import { Component, html } from "/atom/components/resources/atom-component.js";

class Counter extends Component {
    state = { count: 0 };

    connect() {
        this.on("click", "button", () => {
            this.setState(state => ({ count: state.count + 1 }));
        });
    }

    render() {
        return html`<button type="button">Count: ${this.state.count}</button>`;
    }
}

customElements.define("demo-counter", Counter);
```

- `connect()` runs on connection, before the first render. `disconnect()` runs on removal. State survives reconnecting.
- `setState(objectOrFunction)` merges state and batches rendering into a microtask. Direct state assignments require `requestUpdate()`.
- `update()` renders immediately while connected. `rendered()` runs after morphdom updates.
- `on(type, selector, handler)` delegates within the component. The handler receives `(event, matchingElement)` and excludes nested component events.
- `listen(target, type, handler, options)` registers a listener until disconnect. `signal` exposes the connection's AbortSignal for other work.
- `emit(name, detail)` dispatches a bubbling CustomEvent.
- Native `observedAttributes` and `attributeChangedCallback` work normally. Call `super.attributeChangedCallback()` when overriding the callback to schedule rendering.

`html` supports text, nested templates, arrays, null/false for absent content, and scalar attribute values. False/null remove attributes; true produces an empty attribute. Use strings for ARIA boolean values, such as `aria-expanded=${String(open)}`. Attributes may be quoted or unquoted. Dynamic values become DOM text or attribute values, never executable markup. Dynamic event attributes, style, and srcdoc are rejected. Template source and URL-bearing attribute destinations must be trusted application code. Do not interpolate tag names, attribute names, comments, or script/style bodies. There is no raw-HTML escape hatch or `@click` syntax; use delegated listeners.

The component owns all its rendered children: initial child content is replaced. Nested Component instances retain their own children during updates; parent templates control their attributes. Give reorderable elements stable, unique IDs for morphdom identity. Bind an input's `value` to local state to preserve typed text on render. The renderer does not override intentional programmatic value changes.

## Lookup

```html
<atom-lookup name="customer" aria-label="Customer" placeholder="Find a customer…"></atom-lookup>
```

```js
import "/atom/components/resources/atom-controls.js";
const lookup = document.querySelector("atom-lookup");
lookup.options = [{ value: "42", label: "Aster Studio" }];
// Alternatively, a provider returning the same shape:
lookup.search = async (query, { signal }) => {
    const response = await fetch(`/customers?q=${encodeURIComponent(query)}`, { signal });
    if (!response.ok) throw new Error("Search failed");
    return response.json();
};
lookup.addEventListener("select", event => console.log(event.detail.value));
```

Supports 200ms input debounce, cancellation and stale-response protection, loading/empty/error states, arrows/Enter/Escape, and outside-click dismissal. `value` is the selected ID; `label` is its display text. Typing clears the selected value. Assign options before setting an initial value, or supply a `label` attribute for remote values. `select` exposes `{ value, label, item }`; selection also emits `change` with `{ value }`.

## Date picker

```html
<atom-datepicker name="appointment" aria-label="Appointment"
    min="2026-01-01" max="2027-12-31" value="2026-11-17"></atom-datepicker>
```

Dates use local calendar days in `YYYY-MM-DD` format. Includes month navigation, min/max limits, arrow navigation between dates, Escape dismissal, and focus restoration after selection. Buttons use native keyboard behavior; this is a labelled group of date buttons, not an ARIA grid. Selecting a date emits `change` with `{ value }`. The `value` property validates date strings; direct HTML attributes should also contain valid dates.

Both controls support `name`, `value`, and `disabled`, submit through native hidden inputs, and restore their initial values on form reset. Their custom `change` events describe committed selection; native input events also bubble while typing. They do not yet implement required-field validation, form-associated custom-element APIs, remote pagination, or localization configuration. Date labels use the browser locale; the calendar uses a Sunday-first week.

## Verification

Run `composer components:check` from the monorepo root. In `packages/components/tests/Client`, run `npm install`, `npx playwright install chromium`, then `npm test`. To use an installed browser instead, set `ATOM_BROWSER_PATH` to its executable. Browser dependencies are test-only; shipping the library requires no npm installation.

Standalone classes declare their default tag in `static name`. Register explicitly with `Component.register(Calendar, DateNavigation, Panel)` (passing one or more classes); repeated calls for the same class are safe and conflicting definitions throw. The Vue catalogue and composed controls use the same helper.

Classes may declare `static dependencies = [ChildComponent]`. `Component.register` recursively registers these classes before the parent, deduplicates shared dependencies, and rejects cycles. Existing controls declare their primitive dependencies this way.

The standalone WebComponents project keeps JavaScript in `WebComponents/packages/components/src/` and CSS in `WebComponents/packages/components/styles/`. Classes declare `static styles` with stylesheet URLs. Registration loads component and dependency CSS with deduplicated links. The Vue demo uses automatic loading. Applications importing `@atom-fm/components/styles.css` can set `Component.autoStyles = false` before registration; this leaves existing links intact. Styles load asynchronously.

The standalone `atom-select` composes Panel and List with static dependency/style metadata. It provides a select-only combobox, disabled options, explicit commit/cancel, bubbling select/change events, and native hidden-input form values/reset. The Vue catalogue includes `/#/select`.

Standalone `atom-multi-select` uses Panel and List with static dependency/styles metadata. Selected items render as keyboard-removable badges; options toggle without closing. Its array `values` property maps to repeated native form fields, and the Vue demo includes `/#/multi-select`. List gains an optional multiple-selection mode while preserving its default single selection behavior.

`atom-item` now provides the standalone controls' default item presentation. List, Select, MultiSelect and Lookup accept an `itemComponent` class, passed the complete `item` data and `presentation` (`option` or `value`). Controls keep interaction and form semantics; renderer classes remain presentational. The Vue pages demonstrate default rendering and a shared custom CustomerItem class.

Calendar and DatePicker now support `range` mode and a snapshot `rangeValue = { start, end }` property. Existing DateNavigation synchronizes offset calendars and pending previews. Calendar emits partial/complete steps; DatePicker drafts until completion and submits named start/end fields. The Vue Calendar and DatePicker pages demonstrate range selection without adding a new component type.
