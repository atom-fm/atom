import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const { chromium } = await import(process.env.ATOM_PLAYWRIGHT_MODULE || "playwright");
const resources = new URL("../../src/Modules/Client/Resources/", import.meta.url);
const browser = await chromium.launch({
    headless: true,
    ...(process.env.ATOM_BROWSER_PATH ? { executablePath: process.env.ATOM_BROWSER_PATH } : {}),
});

try {
    for (const morphdom of [false, true]) {
        const page = await browser.newPage();
        const requests = [];
        const errors = [];
        page.on("pageerror", error => errors.push(error.message));
        page.setDefaultTimeout(5000);
        await page.route("http://atom.test/**", async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.pathname.endsWith(".js")) {
                const body = await readFile(new URL(url.pathname.slice(1), resources));
                await route.fulfill({ contentType: "application/javascript", body });
                return;
            }

            const action = request.method() === "POST" && url.pathname === "/archive";
            const path = action ? "/archive?updated=1" : url.pathname + url.search;
            requests.push([request.method(), path]);
            await route.fulfill({ headers: action ? { "X-Atom-Uri": path } : {}, contentType: "text/html", body: `<!doctype html>
                <style>
                    body { height: 2400px }
                    main, aside { height: 200px; overflow: auto }
                    .space { height: 1600px }
                </style>
                <aside><div class="space"></div></aside>
                <main atom:update-root>
                    <output>${path.replaceAll("&", "&amp;")}</output>
                    <a id="page" href="?page=2" atom:navigate atom:preserve-scroll>Next</a>
                    <a id="state" href="?page=3" atom:navigate atom:preserve-state>Next with state</a>
                    <a id="section" href="#details" atom:navigate>Details</a>
                    <a id="article" href="/article#details" atom:navigate>Read article</a>
                    <form id="filters" action="/list?obsolete=1" method="get" atom:navigate atom:preserve-scroll>
                        <input name="query" value="${url.searchParams.has("query") ? "two words" : ""}" required>
                        <input type="checkbox" name="tag" value="a" checked>
                        <input type="checkbox" name="tag" value="b" checked>
                        <input type="checkbox" name="unchecked">
                        <input name="disabled" disabled>
                        <textarea name="note">one\ntwo</textarea>
                        <button id="apply" name="mode" value="filter">Apply</button>
                        <button id="alternate" formaction="/other">Other list</button>
                    </form>
                    <form id="editor" method="post" atom:preserve-state>
                        <input id="draft-title" name="title" value="Server title">
                        <textarea id="draft-summary" name="summary">Server summary</textarea>
                        <select id="draft-status" name="status">
                            <option>Draft</option><option>Review</option>
                        </select>
                    </form>
                    <a id="clear" href="/list" atom:navigate atom:preserve-scroll atom:preserve-state>Clear</a>
                    <div class="space"></div><h2 id="details">Details</h2><div class="space"></div>
                </main><script src="/atom.js"></script>
                ${morphdom ? '<script src="/morphdom.js"></script><script src="/atom-morphdom.js"></script>' : ''}` });
        });

        await page.goto("http://atom.test/list");
        const click = id => page.evaluate(id => document.getElementById(id).click(), id);
        const rendered = path => page.waitForFunction(path => document.querySelector("output").value === path, path);
        const scroll = (main, y) => page.evaluate(([main, y]) => {
            document.querySelector("main").scrollTop = main;
            document.querySelector("aside").scrollTop = 45;
            window.scrollTo(0, y);
        }, [main, y]);
        const position = () => page.evaluate(() =>
            [document.querySelector("main").scrollTop, window.scrollY, document.querySelector("aside").scrollTop]);

        await scroll(380, 90);
        await click("page");
        await rendered("/list?page=2");
        assert.deepEqual(await position(), [380, 90, 45]);
        assert.deepEqual(requests.at(-1), ["GET", "/list?page=2"]);

        await click("state");
        await rendered("/list?page=3");
        assert.deepEqual(await position(), [0, 0, 45]);
        assert.deepEqual(requests.at(-1), ["POST", "/list?page=3"]);

        await scroll(240, 160);
        await page.goBack();
        await rendered("/list?page=2");
        assert.deepEqual(await position(), [380, 90, 45]);
        await page.goForward();
        await rendered("/list?page=3");
        assert.deepEqual(await position(), [240, 160, 45]);

        await page.evaluate(() => window.Atom.navigate("/archive"));
        await rendered("/archive");
        assert.deepEqual(await position(), [0, 0, 45]);
        await scroll(180, 75);
        await page.evaluate(() => window.Atom.action("refresh"));
        await rendered("/archive?updated=1");
        assert.deepEqual(await position(), [180, 75, 45]);
        const count = requests.length;
        await click("section");
        await page.waitForFunction(() => document.querySelector("main").scrollTop > 1000);
        assert.equal(requests.length, count);
        await page.goBack();
        assert.deepEqual(await position(), [180, 75, 45]);

        await click("article");
        await rendered("/article");
        assert.equal(new URL(page.url()).hash, "#details");
        assert.ok((await position())[0] > 1000);

        await page.goto("http://atom.test/list");
        await click("apply");
        assert.equal(await page.locator("#filters :invalid").count(), 1);
        const query = page.locator("[name=query]");
        await query.fill("two words");
        await scroll(220, 85);
        const submitted = page.waitForRequest(req => req.url().includes("mode=filter"));
        await query.press("Enter");
        const filtered = "/list?query=two+words&tag=a&tag=b&note=one%0D%0Atwo&mode=filter";
        await rendered(filtered);
        assert.equal((await submitted).resourceType(), "xhr");
        assert.deepEqual(requests.at(-1), ["GET", filtered]);
        assert.deepEqual(await position(), [220, 85, 45]);
        await page.goBack();
        await rendered("/list");
        assert.equal(await query.inputValue(), "");
        assert.deepEqual(await position(), [220, 85, 45]);

        await query.fill("two words");
        await scroll(220, 85);
        await page.locator("#filters").evaluate(form => {
            form.removeAttribute("method");
            form.removeAttribute("atom:preserve-scroll");
            form.requestSubmit(document.getElementById("alternate"));
        });
        await rendered("/other?query=two+words&tag=a&tag=b&note=one%0D%0Atwo");
        assert.deepEqual(await position(), [0, 0, 45]);

        // Observe the decision and suppress the native submission in this test.
        const native = await page.evaluate(() => {
            const form = document.getElementById("filters");
            const button = document.getElementById("apply");
            const intercepted = [];
            document.addEventListener("submit", event => {
                intercepted.push(event.defaultPrevented);
                event.preventDefault();
            });
            for (const [attr, value] of [["formmethod", "post"], ["formtarget", "_blank"], ["formaction", "https://other.test/"]]) {
                button.setAttribute(attr, value);
                form.requestSubmit(button);
                button.removeAttribute(attr);
            }
            return intercepted;
        });
        assert.deepEqual(native, [false, false, false]);

        await page.goto("http://atom.test/list");
        await page.locator("#filters").evaluate(form => form.setAttribute("atom:preserve-state", ""));
        await query.fill("two words");
        await page.locator("#draft-title").fill("Unsaved title");
        await page.locator("#draft-summary").fill("Unsaved summary");
        await page.locator("#draft-status").selectOption("Review");
        await scroll(220, 85);
        const checkDraft = async () => {
            assert.equal(await page.locator("#draft-title").inputValue(), "Unsaved title");
            assert.equal(await page.locator("#draft-summary").inputValue(), "Unsaved summary");
            assert.equal(await page.locator("#draft-status").inputValue(), "Review");
            assert.deepEqual(await position(), [220, 85, 45]);
        };
        await click("apply");
        await rendered(filtered);
        assert.deepEqual(requests.at(-1), ["POST", filtered]);
        await checkDraft();
        await click("clear");
        await rendered("/list");
        assert.deepEqual(requests.at(-1), ["POST", "/list"]);
        assert.equal(await query.inputValue(), "");
        await checkDraft();
        assert.deepEqual(errors, []);
        await page.close();
    }
    console.log("PASS: enhanced navigation scroll, history, and anchors with both update engines.");
} finally {
    await browser.close();
}
