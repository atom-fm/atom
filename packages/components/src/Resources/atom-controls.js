import { Component, html } from "./atom-component.js";

let sequence = 0;

export class LookupSearch extends Component {
    static observedAttributes = ["name", "value", "disabled", "placeholder", "label"];
    state = { query: "", results: [], open: false, active: -1, loading: false, error: "" };
    #options = [];
    #search;
    #timer;
    #revision = 0;
    #id = `atom-lookup-${++sequence}`;
    #provider;

    /** Optional async (query, { signal }) => [{ value, label }]. */
    get search() { return this.#provider; }
    set search(provider) {
        this.#provider = provider;
        this.cancelSearch();
        if (this.isConnected && this.state.open) this.load();
    }
    get options() { return this.#options; }
    set options(options) {
        this.#options = options;
        if (this.isConnected && this.state.open) this.load();
    }
    get value() { return this.getAttribute("value") || ""; }
    set value(value) { this.setAttribute("value", value ?? ""); }
    get disabled() { return this.hasAttribute("disabled"); }
    set disabled(value) { this.toggleAttribute("disabled", Boolean(value)); }

    attributeChangedCallback(name, previous, value) {
        if (previous !== value && (name === "value" || name === "label")) {
            this.state.query = this.getAttribute("label") ||
                this.options.find(item => String(item.value) === this.value)?.label || "";
        }
        if (name === "disabled" && value !== null) this.close();
        super.attributeChangedCallback();
    }

    connect() {
        this.defaultValue = this.value;
        this.defaultLabel = this.getAttribute("label");
        if (!this.state.query) {
            this.state.query = this.getAttribute("label") ||
                this.options.find(item => String(item.value) === this.value)?.label || "";
        }
        this.on("input", "[data-search]", (event, input) => {
            this.cancelSearch();
            this.value = "";
            this.removeAttribute("label");
            this.setState({ query: input.value, open: true, active: -1, results: [], loading: true, error: "" });
            this.#timer = setTimeout(() => this.load(), 200);
        });
        this.on("focusin", "[data-search]", () => {
            if (!this.disabled) this.load();
        });
        this.on("keydown", "[data-search]", event => {
            if (this.disabled) return;
            if (event.key === "Escape" || event.key === "Tab") {
                this.close();
                return;
            }
            if (event.key === "Enter" && this.state.open && this.state.active >= 0) {
                event.preventDefault();
                this.select(this.state.active);
            }
            if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                event.preventDefault();
                if (!this.state.open) { this.load(); return; }
                const count = this.state.results.length;
                const direction = event.key === "ArrowDown" ? 1 : -1;
                this.setState({ active: count ? (this.state.active + direction + count) % count : -1 });
            }
        });
        this.on("pointerdown", "[data-option]", event => event.preventDefault());
        this.on("click", "[data-option]", (event, option) => this.select(Number(option.dataset.option)));
        this.listen(this.ownerDocument, "pointerdown", event => {
            if (!this.contains(event.target)) this.close();
        });
        this.listen(this, "focusout", event => {
            if (!this.contains(event.relatedTarget)) this.close();
        });
        const form = this.closest("form");
        if (form) this.listen(form, "reset", event => queueMicrotask(() => {
            if (event.defaultPrevented || !this.isConnected) return;
            this.value = this.defaultValue;
            if (this.defaultLabel === null) this.removeAttribute("label");
            else this.setAttribute("label", this.defaultLabel);
            this.close();
        }));
    }

    disconnect() { this.cancelSearch(); }

    cancelSearch() {
        clearTimeout(this.#timer);
        this.#search?.abort();
        this.#revision++;
    }

    close() {
        this.cancelSearch();
        this.setState({ open: false, loading: false, active: -1 });
    }

    async load() {
        if (this.disabled || !this.isConnected) return;
        this.cancelSearch();
        const revision = this.#revision;
        const controller = this.#search = new AbortController();
        this.setState({ open: true, loading: true, error: "", results: [], active: -1 });
        try {
            const results = this.search
                ? await this.search(this.state.query, { signal: controller.signal })
                : this.options.filter(item => String(item.label).toLocaleLowerCase().includes(this.state.query.toLocaleLowerCase()));
            if (revision !== this.#revision || controller.signal.aborted || !this.isConnected) return;
            this.setState({ results, loading: false, active: results.length ? 0 : -1 });
        } catch (error) {
            if (revision !== this.#revision || controller.signal.aborted || !this.isConnected) return;
            this.setState({ results: [], loading: false, error: "Unable to load results." });
        }
    }

    select(index) {
        if (this.disabled) return;
        const item = this.state.results[index];
        if (!item) return;
        this.value = item.value;
        this.setAttribute("label", item.label);
        this.setState({ query: String(item.label) });
        this.close();
        this.emit("select", { value: this.value, label: item.label, item });
        this.emit("change", { value: this.value });
    }

    rendered() {
        if (this.state.open) this.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: "nearest" });
    }

    render() {
        const state = this.state;
        return html`
            <input type="hidden" name=${this.getAttribute("name")} value=${this.value} disabled=${this.disabled}>
            <input id=${`${this.#id}-input`} data-search type="text" role="combobox" autocomplete="off"
                aria-label=${this.getAttribute("aria-label") || "Search options"} aria-autocomplete="list"
                aria-controls=${`${this.#id}-list`} aria-expanded=${String(state.open)}
                aria-activedescendant=${state.open && state.active >= 0 ? `${this.#id}-${state.active}` : null}
                placeholder=${this.getAttribute("placeholder") || "Search…"} value=${state.query} disabled=${this.disabled}>
            <div class="atom-control-panel" hidden=${!state.open}>
                <div role="status">${state.loading ? "Searching…" : state.error || (!state.results.length ? "No results." : "")}</div>
                <div id=${`${this.#id}-list`} role="listbox" aria-label="Search results" aria-busy=${String(state.loading)}>
                    ${state.results.map((item, index) => html`
                        <div role="option" id=${`${this.#id}-${index}`} data-option=${index}
                            aria-selected=${String(index === state.active)}>${item.label}</div>
                    `)}
                </div>
            </div>`;
    }
}

function dateValue(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function parseDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || "")) return null;
    const date = new Date(`${value}T12:00:00`);
    return !Number.isNaN(date.getTime()) && dateValue(date) === value ? date : null;
}

export class DatePicker extends Component {
    static observedAttributes = ["value", "name", "disabled", "min", "max"];
    state = { open: false, month: new Date(new Date().getFullYear(), new Date().getMonth(), 1, 12) };
    #focus;

    get value() { return this.getAttribute("value") || ""; }
    set value(value) {
        if (value && !parseDate(String(value))) throw new TypeError("Expected a date in YYYY-MM-DD format.");
        this.setAttribute("value", value || "");
    }
    get disabled() { return this.hasAttribute("disabled"); }
    set disabled(value) { this.toggleAttribute("disabled", Boolean(value)); }

    attributeChangedCallback(name, previous, value) {
        if (name === "disabled" && value !== null) this.state.open = false;
        super.attributeChangedCallback();
    }

    connect() {
        this.defaultValue = this.value;
        this.on("click", "[data-toggle]", () => {
            if (this.disabled) return;
            if (this.state.open) this.close();
            else this.open();
        });
        this.on("click", "[data-month]", (event, button) => {
            if (this.disabled) return;
            const month = new Date(this.state.month);
            month.setMonth(month.getMonth() + Number(button.dataset.month));
            this.setState({ month });
        });
        this.on("click", "[data-date]", (event, button) => {
            const value = button.dataset.date;
            if (this.disabled || !this.allowed(value)) return;
            this.value = value;
            this.close(true);
            this.emit("change", { value });
        });
        this.on("keydown", "button", (event, button) => {
            if (event.key === "Escape") { event.preventDefault(); this.close(true); return; }
            if (!button.dataset.date) return;
            const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
            if (!(event.key in moves)) return;
            event.preventDefault();
            const date = parseDate(button.dataset.date);
            date.setDate(date.getDate() + moves[event.key]);
            const value = dateValue(date);
            if (!this.allowed(value)) return;
            this.#focus = value;
            this.setState({ month: new Date(date.getFullYear(), date.getMonth(), 1, 12) });
        });
        this.listen(this.ownerDocument, "pointerdown", event => {
            if (!this.contains(event.target)) this.close();
        });
        this.listen(this, "focusout", event => {
            if (!this.contains(event.relatedTarget)) this.close();
        });
        const form = this.closest("form");
        if (form) this.listen(form, "reset", event => queueMicrotask(() => {
            if (event.defaultPrevented || !this.isConnected) return;
            this.value = this.defaultValue;
            this.close();
        }));
    }

    allowed(value) {
        const min = this.getAttribute("min");
        const max = this.getAttribute("max");
        return (!parseDate(min) || value >= min) && (!parseDate(max) || value <= max);
    }

    open() {
        let date = parseDate(this.value) || new Date();
        if (parseDate(this.getAttribute("min")) && dateValue(date) < this.getAttribute("min")) date = parseDate(this.getAttribute("min"));
        if (parseDate(this.getAttribute("max")) && dateValue(date) > this.getAttribute("max")) date = parseDate(this.getAttribute("max"));
        this.#focus = dateValue(date);
        this.setState({ open: true, month: new Date(date.getFullYear(), date.getMonth(), 1, 12) });
    }

    close(focus = false) {
        this.#focus = null;
        this.setState({ open: false });
        if (focus) this.querySelector("[data-toggle]")?.focus();
    }

    rendered() {
        if (!this.#focus) return;
        const button = this.querySelector(`[data-date="${this.#focus}"]`);
        this.#focus = null;
        button?.focus();
    }

    renderCalendar() {
        const month = this.state.month;
        const count = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        const title = month.toLocaleDateString(undefined, { month: "long", year: "numeric" });
        return html`<div class="atom-control-panel" role="group" aria-label="Choose a date">
            <div class="atom-calendar-heading">
                <button type="button" data-month="-1" aria-label="Previous month">‹</button>
                <span aria-live="polite">${title}</span>
                <button type="button" data-month="1" aria-label="Next month">›</button>
            </div>
            <div class="atom-calendar-days">
                ${["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"].map(day => html`<span aria-hidden="true">${day}</span>`)}
                ${Array.from({ length: month.getDay() }, () => html`<span></span>`)}
                ${Array.from({ length: count }, (_, index) => {
                    const date = new Date(month.getFullYear(), month.getMonth(), index + 1, 12);
                    const value = dateValue(date);
                    return html`<button type="button" data-date=${value} disabled=${!this.allowed(value)}
                        aria-label=${date.toLocaleDateString(undefined, { dateStyle: "full" })}
                        aria-pressed=${String(value === this.value)}
                        aria-current=${value === dateValue(new Date()) ? "date" : null}>${index + 1}</button>`;
                })}
            </div>
        </div>`;
    }

    render() {
        return html`
            <input type="hidden" name=${this.getAttribute("name")} value=${this.value} disabled=${this.disabled}>
            <button type="button" data-toggle aria-label=${this.getAttribute("aria-label") || "Choose a date"}
                aria-expanded=${String(this.state.open)} disabled=${this.disabled}>${this.value || "Choose a date"}</button>
            ${this.state.open ? this.renderCalendar() : null}`;
    }
}

customElements.define("atom-lookup", LookupSearch);
customElements.define("atom-datepicker", DatePicker);

function togglePassword(button) {
    const fieldId = button.getAttribute("aria-controls") || "";
    const field = fieldId === "" ? null : document.getElementById(fieldId);

    if (field === null || !field.hasAttribute("data-atom-password-input")) {
        return;
    }

    const reveal = field.type === "password";
    const label = button.getAttribute(reveal ? "data-hide-label" : "data-show-label") || "";

    field.type = reveal ? "text" : "password";
    button.setAttribute("aria-pressed", reveal ? "true" : "false");

    if (label !== "") {
        button.textContent = label;
        button.setAttribute("aria-label", label);
    }
}

document.addEventListener("click", event => {
    const button = event.target.closest?.("[data-atom-password-toggle]");
    if (button && !event.defaultPrevented) {
        event.preventDefault();
        togglePassword(button);
    }
});
document.documentElement.classList.add("atom-components-enhanced");
