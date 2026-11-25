import "./morphdom.js";

const marker = /__atom_value_(\d+)__/g;

class Template {
    constructor(strings, values) {
        this.strings = strings;
        this.values = values;
    }

    fragment(document) {
        const template = document.createElement("template");
        template.innerHTML = this.strings.reduce((result, part, index) =>
            result + (index ? `__atom_value_${index - 1}__` : "") + part, "");
        const visit = node => {
            if (node.nodeType === 3) {
                const parts = node.textContent.split(marker);
                if (parts.length === 1) return;
                const replacement = document.createDocumentFragment();
                parts.forEach((part, index) => {
                    if (index % 2) append(replacement, this.values[Number(part)], document);
                    else replacement.append(document.createTextNode(part));
                });
                node.replaceWith(replacement);
                return;
            }
            if (node.nodeType === 1) {
                for (const attribute of [...node.attributes]) {
                    if (!attribute.value.match(marker)) continue;
                    if (/^on/i.test(attribute.name) || /^(srcdoc|style)$/i.test(attribute.name)) {
                        throw new TypeError("Use delegated events and static styles in html templates.");
                    }
                    const exact = /^__atom_value_(\d+)__$/.exec(attribute.value);
                    const value = exact ? this.values[Number(exact[1])] : attribute.value.replace(marker,
                        (_, index) => String(this.values[Number(index)] ?? ""));
                    node.removeAttribute(attribute.name);
                    if (value !== false && value != null) {
                        if (value instanceof Template || Array.isArray(value) || typeof value === "object") {
                            throw new TypeError("Attribute values must be scalar.");
                        }
                        node.setAttribute(attribute.name, value === true ? "" : String(value));
                    }
                }
                if (node.localName === "template") visit(node.content);
            }
            for (const child of [...node.childNodes]) visit(child);
        };
        visit(template.content);
        return template.content;
    }
}

function append(parent, value, document) {
    if (value == null || value === false) return;
    if (Array.isArray(value)) value.forEach(item => append(parent, item, document));
    else if (value instanceof Template) parent.append(value.fragment(document));
    else parent.append(document.createTextNode(String(value)));
}

/** Interpolate text, scalar attributes, nested templates, arrays, and null. */
export function html(strings, ...values) {
    return new Template(strings, values);
}

/** A light-DOM custom element. Subclasses own their rendered children. */
export class Component extends HTMLElement {
    state = {};
    #lifetime;
    #pending = false;

    get [Symbol.for("atom.component")]() { return true; }

    connectedCallback() {
        if (this.#lifetime) return;
        this.#lifetime = new AbortController();
        this.connect();
        this.update();
    }

    disconnectedCallback() {
        this.#lifetime?.abort();
        this.#lifetime = null;
        this.disconnect();
    }

    attributeChangedCallback() {
        this.requestUpdate();
    }

    get signal() { return this.#lifetime?.signal; }
    connect() {}
    disconnect() {}
    rendered() {}
    render() { return html``; }

    setState(patch) {
        Object.assign(this.state, typeof patch === "function" ? patch(this.state) : patch);
        this.requestUpdate();
    }

    requestUpdate() {
        if (this.#pending || !this.#lifetime) return;
        this.#pending = true;
        queueMicrotask(() => {
            this.#pending = false;
            if (this.#lifetime && this.isConnected) this.update();
        });
    }

    update() {
        if (!this.#lifetime || !this.isConnected) return;
        const target = this.ownerDocument.createElement("div");
        append(target, this.render(), this.ownerDocument);
        globalThis.morphdom(this, target, {
            childrenOnly: true,
            onBeforeElChildrenUpdated: from => !(from instanceof Component),
        });
        this.rendered();
    }

    /** Delegation is limited to this component, excluding nested components. */
    on(type, selector, handler) {
        this.listen(this, type, event => {
            const target = event.target instanceof Element ? event.target : event.target.parentElement;
            const match = target?.closest(selector);
            if (!match || !this.contains(match)) return;
            for (let node = target; node && node !== this; node = node.parentElement) {
                if (node instanceof Component) return;
            }
            handler.call(this, event, match);
        });
    }

    listen(target, type, handler, options = {}) {
        if (!this.signal) throw new Error("Register listeners in connect().");
        target.addEventListener(type, handler, { ...options, signal: this.signal });
    }

    emit(name, detail) {
        return this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
    }
}
