import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const source = await readFile(new URL("../../src/Modules/Client/Resources/atom.js", import.meta.url), "utf8");
function scenario(uri, status = 200) {
    const requests = [], history = [], listeners = {};
    const location = new URL("http://example.test/catalog?new=1");
    let updates = 0;
    const body = { querySelector: () => null, prepend() {} };
    const document = {
        body,
        createElement: () => ({ setAttribute() {} }),
        querySelector: () => null,
        addEventListener() {},
        documentElement: { classList: { add() {} } },
        implementation: { createHTMLDocument: () => ({ documentElement: {}, body, title: "Updated", querySelector: () => null }) },
    };
    class Xhr {
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader() {}
        getResponseHeader(name) { return name === "X-Atom-Uri" ? uri : null; }
        send() {
            requests.push(this);
            this.readyState = 4;
            this.status = status;
            this.responseText = "<html>updated</html>";
            this.responseURL = location.href;
            this.onreadystatechange();
        }
    }
    const window = {
        location,
        scrollTo() {},
        history: {
            state: null,
            replaceState(state) { this.state = state; },
            pushState(state, _title, url) { this.state = state; history.push(url); location.href = url; },
        },
        addEventListener(name, callback) { listeners[name] = callback; },
    };
    vm.runInNewContext(source, { window, document, URL, XMLHttpRequest: Xhr, FormData: class { append() {} } });
    window.Atom.setUpdateEngine({ update() { ++updates; } });
    window.Atom.action("save");
    return { requests, history, listeners, window, updates };
}
const saved = scenario("/catalog?id=42&section=details");
assert.equal(saved.requests.length, 1);
assert.equal(saved.requests[0].method, "POST");
assert.equal(saved.updates, 1);
assert.deepEqual(saved.history, ["http://example.test/catalog?id=42&section=details"]);
saved.listeners.popstate();
assert.equal(saved.requests[1].method, "GET");
assert.equal(saved.history.length, 1, "back/forward must not create another history entry");
assert.deepEqual(scenario("/catalog?new=1").history, [], "same URL should not create a history entry");
assert.deepEqual(scenario("https://outside.test/catalog").history, [], "cross-origin hints are ignored");
assert.deepEqual(scenario("http://[").history, [], "malformed hints are ignored");
assert.deepEqual(scenario("/catalog?id=42", 422).history, [], "validation responses must not change the URL");
assert.equal(scenario("/catalog?id=42", 500).updates, 0);
console.log("PASS: URI history synchronization, same-origin handling, and back/forward behavior.");
