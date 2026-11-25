import assert from "node:assert/strict";
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";

const { chromium } = await import(process.env.ATOM_PLAYWRIGHT_MODULE || "playwright");
const assets = new Map(await Promise.all(["atom.js", "morphdom.js", "atom-morphdom.js"].map(async name => [
    `/${name}`, await readFile(new URL(`../../src/Modules/Client/Resources/${name}`, import.meta.url)),
])));
const markup = (message = "", title = "") => `<!doctype html>
    <main atom:update-root>
        <p id="result">${message}</p>
        <form method="post" action="/" atom:submit="save">
            <input type="hidden" name="_action" value="save">
            <input type="text" name="title" value="${title}" required>
            <button id="save" type="submit" name="source" value="primary">Save</button>
            <button id="alternate" atom:action="saveDraft" formnovalidate name="source" value="draft">Save draft</button>
        </form>
    </main>
    <script src="/atom.js"></script>`;

let responseGate;
let responseStatus = 200;
let releaseResponse;
let responseHeld;
const submissions = [];
const server = createServer(async (request, response) => {
    if (assets.has(request.url)) {
        response.writeHead(200, { "Content-Type": "application/javascript" }).end(assets.get(request.url));
        return;
    }
    if (request.method === "POST") {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const body = await new Response(Buffer.concat(chunks), { headers: { "Content-Type": request.headers["content-type"] } }).formData();
        submissions.push(Object.fromEntries(body));
        if (responseGate) {
            responseHeld();
            await responseGate;
        }
        response.writeHead(responseStatus, { "Content-Type": "text/html" }).end(markup(body.get("_action"), "Saved title"));
        return;
    }
    response.writeHead(200, { "Content-Type": "text/html" }).end(markup());
});
await new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
let browser;

try {
    browser = await chromium.launch({
        headless: true,
        ...(process.env.ATOM_BROWSER_PATH ? { executablePath: process.env.ATOM_BROWSER_PATH } : {}),
    });
    const page = await browser.newPage();
    const errors = [];
    page.on("pageerror", error => errors.push(error.message));
    page.setDefaultTimeout(5000);
    await page.addInitScript(() => {
        window.submitRequests = 0;
        window.invalidFields = 0;
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (...args) {
            window.submitRequests++;
            return send.apply(this, args);
        };
        document.addEventListener("invalid", () => window.invalidFields++, true);
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    const title = page.locator("[name=title]");
    const save = page.locator("#save");
    const alternate = page.locator("#alternate");
    const result = page.locator("#result");
    const requestCount = () => page.evaluate(() => window.submitRequests);
    const nextPost = () => page.waitForResponse(response => response.request().method() === "POST");

    await save.click();
    assert.equal(await page.evaluate(() => window.invalidFields), 1);
    assert.equal(await requestCount(), 0);

    const draft = nextPost();
    await alternate.click();
    await draft;
    await result.filter({ hasText: "saveDraft" }).waitFor();
    assert.deepEqual(submissions[0], { _action: "saveDraft", title: "", source: "draft", _state: "" });

    responseGate = new Promise(resolve => { releaseResponse = resolve; });
    const held = new Promise(resolve => { responseHeld = resolve; });
    await title.fill("A valid title");
    const saveResponse = nextPost();
    await title.press("Enter");
    await held;
    await alternate.click();
    await page.evaluate(() => document.querySelector("form").requestSubmit());
    assert.equal(await requestCount(), 2);
    releaseResponse();
    await saveResponse;
    await result.filter({ hasText: /^save$/ }).waitFor();
    assert.deepEqual(submissions[1], { _action: "save", title: "A valid title", source: "primary", _state: "" });

    responseGate = null;
    responseStatus = 500;
    await title.fill("Retry this");
    const failed = nextPost();
    await save.click();
    assert.equal((await failed).status(), 500);
    await page.waitForFunction(() => !document.querySelector("#save").disabled);
    responseStatus = 200;
    const retried = nextPost();
    await save.click();
    assert.equal((await retried).status(), 200);
    assert.equal(submissions.length, 4);
    assert.deepEqual(errors, []);
    console.log("PASS: native form validation, submitter actions, duplicate prevention, and retry after failure.");

    for (const engine of ["default", "MorphDOM"]) {
        await page.reload();
        if (engine === "MorphDOM") {
            await page.addScriptTag({ url: "/morphdom.js" });
            await page.addScriptTag({ url: "/atom-morphdom.js" });
        }

        await title.fill("Unnormalized title");
        await title.press("Enter");
        await result.filter({ hasText: /^save$/ }).waitFor();
        assert.equal(submissions.at(-1).title, "Unnormalized title", engine);
        assert.equal(await title.inputValue(), "Saved title", engine);
        assert.equal(await title.evaluate(field => field === document.activeElement), true, engine);
    }
    assert.deepEqual(errors, []);
    console.log("PASS: the focused field shows the saved value with both update engines.");
} finally {
    releaseResponse?.();
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
}
