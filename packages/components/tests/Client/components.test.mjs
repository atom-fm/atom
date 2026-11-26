import assert from "node:assert/strict";
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { resolve, sep } from "node:path";

const { chromium } = await import(process.env.ATOM_PLAYWRIGHT_MODULE || "playwright");
const root = fileURLToPath(new URL("../../", import.meta.url));
const server = createServer(async (request, response) => {
    try {
        const path = resolve(root, `.${new URL(request.url, "http://localhost").pathname}`);
        if (!path.startsWith(root.endsWith(sep) ? root : root + sep)) throw new Error("Invalid path");
        response.setHeader("Content-Type", path.endsWith(".js") ? "application/javascript" : "text/html");
        response.end(await readFile(path));
    } catch {
        response.writeHead(404).end();
    }
});
await new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
const browser = await chromium.launch({
    headless: true,
    ...(process.env.ATOM_BROWSER_PATH ? { executablePath: process.env.ATOM_BROWSER_PATH } : {}),
});

try {
    const page = await browser.newPage();
    const errors = [];
    page.on("pageerror", error => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/tests/Client/fixtures/components-demo.html`);
    await page.waitForFunction(() => customElements.get("atom-lookup"));
    const input = page.locator("[data-search]");
    await input.fill("Bir");
    await page.waitForFunction(() => document.querySelector('[role="option"]')?.textContent.trim() === "Birch & Co.");
    assert.equal(await input.inputValue(), "Bir");
    assert.equal(await input.evaluate(node => node === document.activeElement), true);
    await input.press("Enter");
    assert.equal(await page.locator('input[name="customer"]').inputValue(), "2");
    assert.equal(await input.inputValue(), "Birch & Co.");

    // Deferred providers that ignore abort still cannot overwrite newer results.
    await page.evaluate(() => {
        const lookup = document.querySelector("atom-lookup");
        window.pending = [];
        lookup.search = query => new Promise(resolve => pending.push({ query, resolve }));
    });
    await input.fill("old");
    await page.waitForFunction(() => pending.some(item => item.query === "old"));
    await input.fill("new");
    await page.waitForFunction(() => pending.some(item => item.query === "new"));
    await page.evaluate(() => pending.find(item => item.query === "new").resolve([{ value: "new", label: "New result" }]));
    await page.waitForFunction(() => document.querySelector('[role="option"]')?.textContent.trim() === "New result");
    await page.evaluate(() => pending.find(item => item.query === "old").resolve([{ value: "old", label: "Old result" }]));
    await page.waitForTimeout(30);
    assert.equal((await page.locator('[role="option"]').textContent()).trim(), "New result");
    await input.press("Escape");
    assert.equal(await input.getAttribute("aria-expanded"), "false");

    await page.evaluate(() => {
        document.querySelector("atom-lookup").search = async () => { throw new Error("Unavailable"); };
    });
    await input.fill("failure");
    await page.waitForFunction(() => document.querySelector("atom-lookup [role=status]").textContent === "Unable to load results.");
    await page.evaluate(() => { document.querySelector("atom-lookup").search = async () => []; });
    await page.waitForFunction(() => document.querySelector("atom-lookup [role=status]").textContent === "No results.");
    await page.evaluate(() => { document.querySelector("atom-lookup").disabled = true; });
    assert.equal(await input.isDisabled(), true);
    assert.equal(await page.evaluate(() => new FormData(document.querySelector("form")).has("customer")), false);
    await page.evaluate(() => { document.querySelector("atom-lookup").disabled = false; });

    await page.evaluate(() => {
        const picker = document.querySelector("atom-datepicker");
        picker.value = "2026-02-28";
        picker.setAttribute("min", "2026-02-02");
        picker.setAttribute("max", "2026-03-05");
    });
    await page.locator("[data-toggle]").click();
    assert.equal(await page.locator('[data-date="2026-02-01"]').isDisabled(), true);
    assert.equal(await page.locator("[data-date]").count(), 28);
    await page.locator('[data-date="2026-02-28"]').press("ArrowRight");
    await page.waitForFunction(() => document.activeElement?.dataset.date === "2026-03-01");
    await page.keyboard.press("Enter");
    assert.equal(await page.locator('input[name="appointment"]').inputValue(), "2026-03-01");
    assert.equal(await page.locator("[data-toggle]").evaluate(node => node === document.activeElement), true);
    await page.locator('[type="reset"]').click();
    await page.waitForFunction(() => document.querySelector("atom-datepicker").value === "");

    const core = await page.evaluate(async () => {
        const { Component, html } = await import("/src/Resources/atom-component.js");
        class TestCounter extends Component {
            state = { count: 0 };
            connects = 0;
            renders = 0;
            connect() {
                this.connects++;
                this.on("click", "button", () => this.setState(state => ({ count: state.count + 1 })));
            }
            rendered() { this.renders++; }
            render() {
                return html`<button>${this.state.count}</button><div title=${'" onmouseover="alert(1)'}>
                    ${[html`<span>${"<img src=x onerror=alert(1)>"}</span>`, null]}</div>`;
            }
        }
        customElements.define("test-counter", TestCounter);
        const counter = document.createElement("test-counter");
        document.body.append(counter);
        counter.setState({ count: 1 });
        counter.setState({ count: 2 });
        await Promise.resolve();
        const batched = counter.renders === 2;
        const escaped = !counter.querySelector("img") && !counter.querySelector("[onmouseover]");
        counter.remove();
        document.body.append(counter);
        counter.querySelector("button").click();
        await Promise.resolve();
        const reconnect = counter.state.count === 3 && counter.connects === 2;
        class Parent extends Component {
            render() { return html`<test-counter id="nested"></test-counter><button>Parent</button>`; }
        }
        customElements.define("test-parent", Parent);
        const parent = document.createElement("test-parent");
        document.body.append(parent);
        const nested = parent.querySelector("test-counter");
        nested.setState({ count: 7 });
        await Promise.resolve();
        parent.update();
        const isolated = nested.querySelector("button").textContent === "7";
        let parentClicks = 0;
        parent.on("click", "button", () => parentClicks++);
        nested.querySelector("button").click();
        return { batched, escaped, reconnect, isolated, parentClicks };
    });
    assert.deepEqual(core, { batched: true, escaped: true, reconnect: true, isolated: true, parentClicks: 0 });
    await page.addScriptTag({ url: "/vendor/atom-fm/framework/src/Modules/Client/Resources/atom.js" });
    await page.addScriptTag({ url: "/vendor/atom-fm/framework/src/Modules/Client/Resources/atom-morphdom.js" });
    const preserved = await page.evaluate(async () => {
        const parent = document.querySelector("test-parent");
        const nested = parent.querySelector("test-counter");
        const button = nested.querySelector("button");
        const target = document.createElement("div");
        target.innerHTML = '<test-counter id="nested" title="Updated by server"></test-counter><button>Updated</button>';
        Atom.update(parent, target);
        await Promise.resolve();
        return nested.querySelector("button") === button && nested.getAttribute("title") === "Updated by server";
    });
    assert.equal(preserved, true);
    assert.deepEqual(errors, []);
    if (process.env.ATOM_SCREENSHOT) await page.screenshot({ path: process.env.ATOM_SCREENSHOT, fullPage: true });
    console.log("PASS: lookup keyboard/form values, async races, date bounds/month focus, reset, batching, escaping, reconnect, nested isolation.");
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
