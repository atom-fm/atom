import assert from "node:assert/strict";
import { execSync, spawn } from "node:child_process";
import { once } from "node:events";
import { createServer } from "node:net";
import { fileURLToPath } from "node:url";

const { chromium } = await import(process.env.ATOM_PLAYWRIGHT_MODULE || "playwright");
const php = execSync('php -r "echo PHP_BINARY;"', { encoding: "utf8" }).trim();
const address = createServer();
address.listen(0, "127.0.0.1");
await once(address, "listening");
const host = `127.0.0.1:${address.address().port}`;
await new Promise(resolve => address.close(resolve));

const fixture = fileURLToPath(new URL("fixtures/page-save/server.php", import.meta.url));
const server = spawn(php, ["-S", host, fixture], { stdio: ["ignore", "ignore", "pipe"] });
const stopped = once(server, "close");
let serverLog = "";
server.stderr.setEncoding("utf8");
server.stderr.on("data", chunk => { serverLog += chunk; });
let browser;

try {
    const edit = `http://${host}/records/42/edit`;
    const detail = `http://${host}/records/42`;
    await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error("PHP test server did not start")), 5000);
        server.stderr.on("data", () => {
            if (serverLog.includes(" started")) {
                clearTimeout(timeout);
                resolve();
            }
        });
        stopped.then(() => {
            clearTimeout(timeout);
            reject(new Error("PHP test server exited"));
        }, error => {
            clearTimeout(timeout);
            reject(error);
        });
    });
    browser = await chromium.launch({
        headless: true,
        ...(process.env.ATOM_BROWSER_PATH ? { executablePath: process.env.ATOM_BROWSER_PATH } : {}),
    });

    for (const engine of ["default", "MorphDOM"]) {
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        const requests = [];
        const errors = [];
        page.on("request", request => requests.push(request));
        page.on("pageerror", error => errors.push(error.message));
        const editResponse = await page.goto(edit);
        assert.equal(editResponse.status(), 200, engine);
        if (engine === "MorphDOM") {
            await page.addScriptTag({ url: "/morphdom.js" });
            await page.addScriptTag({ url: "/atom-morphdom.js" });
        }

        await page.getByLabel("Title").fill("Quarterly report");
        const saved = page.waitForResponse(response => response.url() === edit && response.request().method() === "POST");
        await page.getByRole("button", { name: "Save record" }).click();
        const response = await saved;
        if (response.status() >= 400) {
            assert.fail(`${engine}: save returned ${response.status()}\n${(await response.text()).split("</style>").at(-1)}`);
        }
        assert.equal(response.status(), 303, engine);
        assert.equal(response.request().headers()["x-requested-with"], "Atom", engine);
        await page.waitForURL(detail);
        await page.getByRole("heading", { name: "Record details" }).waitFor();
        await page.getByText("Quarterly report", { exact: true }).waitFor();
        const notice = page.getByText("Record saved.", { exact: true });
        await notice.waitFor();
        assert.equal(await notice.count(), 1, engine);

        const back = page.waitForResponse(response => response.url() === edit && response.request().method() === "GET");
        await page.goBack();
        const backResponse = await back;
        assert.equal(backResponse.status(), 200, engine);
        assert.equal(backResponse.request().resourceType(), "xhr", engine);
        await page.waitForURL(edit);
        await page.getByRole("heading", { name: "Edit record" }).waitFor();
        await page.getByRole("button", { name: "Save record" }).waitFor();
        assert.equal(await notice.count(), 0, engine);
        assert.equal(requests.filter(request => request.method() === "POST").length, 1, engine);

        await page.goto(detail);
        await page.getByText("Quarterly report", { exact: true }).waitFor();
        assert.equal(await notice.count(), 0, engine);
        assert.deepEqual(errors, [], engine);
        await page.close();
    }
    console.log("PASS: enhanced form save, one-time success notice, and Back with both update engines.");
} catch (error) {
    console.error(serverLog);
    throw error;
} finally {
    await browser?.close();
    server.kill();
    await stopped.catch(() => {});
}
