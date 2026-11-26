import assert from "node:assert/strict";
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";

const { chromium } = await import(process.env.ATOM_PLAYWRIGHT_MODULE || "playwright");
const assets = new Map(await Promise.all(["atom.js", "morphdom.js", "atom-morphdom.js"].map(async name => [
    `/${name}`, await readFile(new URL(`../../src/Modules/Client/Resources/${name}`, import.meta.url)),
])));
const markup = (message = "", title = "", fieldError = "") => `<!doctype html>
    <main atom:update-root>
        <p id="result">${message}</p>
        <form method="post" action="/" atom:submit="save">
            <input type="hidden" name="_action" value="save">
            <input type="text" name="title" value="${title}" required${fieldError ? ' aria-invalid="true" aria-describedby="title-error"' : ""}>
            ${fieldError ? `<p id="title-error">${fieldError}</p>` : ""}
            <button id="save" type="submit" name="source" value="primary">Save</button>
            <button id="alternate" atom:action="saveDraft" formnovalidate name="source" value="draft">Save draft</button>
        </form>
    </main>
    <script src="/atom.js"></script>`;
const errorPage = (title, message) => `<!doctype html>
    <title>${title}</title>
    <main class="atom-error"><h1>${title}</h1><p>${message}</p></main>`;

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
        const action = body.get("_action");
        if (responseStatus === 500) {
            response.writeHead(500, { "Content-Type": "text/html" })
                .end(errorPage("Something went wrong", "An unexpected error occurred."));
        } else if (action === "save(") {
            response.writeHead(400, { "Content-Type": "text/html" })
                .end(errorPage("Bad request", "The request could not be understood."));
        } else if (body.get("title") === "Duplicate title") {
            response.writeHead(200, { "Content-Type": "text/html" })
                .end(markup("", "Duplicate title", "Choose a different title."));
        } else {
            response.writeHead(200, { "Content-Type": "text/html" }).end(markup(action, "Saved title"));
        }
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
    const actionError = page.getByRole("alert").filter({ hasText: "The server could not complete this action. Please try again." });
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
    const failedResponse = await failed;
    assert.equal(failedResponse.status(), 500);
    assert.equal(failedResponse.request().headers()["x-requested-with"], "Atom");
    await actionError.waitFor();
    await page.waitForFunction(() => !document.querySelector("#save").disabled);
    assert.equal(await title.isVisible(), true);
    assert.equal(await title.inputValue(), "Retry this");
    responseStatus = 200;
    const retried = nextPost();
    await save.click();
    assert.equal((await retried).status(), 200);
    await page.waitForFunction(() => document.querySelector("[name=title]").value === "Saved title");
    await actionError.waitFor({ state: "detached" });
    assert.equal(submissions.length, 4);
    assert.equal(submissions.at(-1).title, "Retry this");
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

        await title.fill("Duplicate title");
        const invalid = nextPost();
        await title.press("Enter");
        const invalidResponse = await invalid;
        assert.equal(invalidResponse.status(), 200, engine);
        assert.equal(invalidResponse.request().headers()["x-requested-with"], "Atom", engine);
        await page.getByText("Choose a different title.", { exact: true }).waitFor();
        assert.equal(await title.inputValue(), "Duplicate title", engine);
        assert.equal(await title.getAttribute("aria-invalid"), "true", engine);
        assert.equal(await title.getAttribute("aria-describedby"), "title-error", engine);
        assert.equal(await save.isEnabled(), true, engine);

        await page.locator("[name=_action]").evaluate(input => { input.value = "save("; });
        const malformed = nextPost();
        await save.click();
        const malformedResponse = await malformed;
        assert.equal(malformedResponse.status(), 400, engine);
        assert.equal(malformedResponse.request().headers()["x-requested-with"], "Atom", engine);
        await page.getByRole("heading", { name: "Bad request" }).waitFor();
        assert.equal(await page.getByText("The request could not be understood.").isVisible(), true, engine);
    }
    assert.deepEqual(errors, []);
    console.log("PASS: saved values, visible field errors, and malformed-action feedback with both update engines.");
} finally {
    releaseResponse?.();
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
}
