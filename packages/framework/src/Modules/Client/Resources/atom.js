(function () {
    var actionAttribute = "atom:action";
    var changeAttribute = "atom:change";
    var inputAttribute = "atom:input";
    var submitAttribute = "atom:submit";
    var navigateAttribute = "atom:navigate";
    var preserveStateAttribute = "atom:preserve-state";
    var preserveScrollAttribute = "atom:preserve-scroll";
    var updateRootAttribute = "atom:update-root";
    var updateEngine = {
        update: function (current, next, options) {
            var state = captureUpdateState(current);

            current.innerHTML = next.innerHTML;
            restoreUpdateState(current, state, !options || options.preserveFocusedValue !== false);
        }
    };
    var inputDebounceMs = 300;
    var submittingForms = new WeakSet();

    // History traversal changes history.state before popstate runs. Keep the
    // outgoing entry's key until its final position has been captured.
    var historySequence = 0;
    var historySession = Date.now().toString(36);
    var scrollPositions = new Map();
    var activeEntry = currentEntry();
    var renderedUrl = new URL(window.location.href);
    var navigationSequence = 0;

    function newEntryKey() {
        return historySession + "-" + (++historySequence);
    }

    function currentEntry() {
        var state = window.history.state || {};
        if (state.atomEntry) return state.atomEntry;

        var key = newEntryKey();
        window.history.replaceState(Object.assign({}, state, { atomEntry: key }), "");
        return key;
    }

    function contentScroll() {
        var main = document.querySelector("main");
        var root = updateRoot(document.body);
        return {
            x: window.scrollX,
            y: window.scrollY,
            mainX: main ? main.scrollLeft : 0,
            mainY: main ? main.scrollTop : 0,
            rootX: root ? root.scrollLeft : 0,
            rootY: root ? root.scrollTop : 0
        };
    }

    function applyContentScroll(position) {
        var main = document.querySelector("main");
        var root = updateRoot(document.body);
        if (main) main.scrollTo({ left: position.mainX, top: position.mainY, behavior: "instant" });
        if (root && root !== main) root.scrollTo({ left: position.rootX, top: position.rootY, behavior: "instant" });
        window.scrollTo({ left: position.x, top: position.y, behavior: "instant" });
    }

    function scrollToDestination(url) {
        applyContentScroll({ x: 0, y: 0, mainX: 0, mainY: 0, rootX: 0, rootY: 0 });
        if (!url.hash) return;

        var fragment = url.hash.slice(1);
        try {
            fragment = decodeURIComponent(fragment);
        } catch (error) {
            // An id may contain a literal percent sign.
        }
        var target = document.getElementById(fragment) || document.getElementsByName(fragment)[0];
        if (target) target.scrollIntoView();
    }

    function captureUpdateState(root) {
        return {
            focus: captureFocus(root),
            scroll: {
                x: window.scrollX || window.pageXOffset || 0,
                y: window.scrollY || window.pageYOffset || 0
            },
            elementScroll: captureElementScroll(root)
        };
    }

    function captureFocus(root) {
        var active = document.activeElement;

        if (!active || !root.contains(active) || !isFocusableStateElement(active)) {
            return null;
        }

        return {
            selector: stableSelector(active),
            value: active.value,
            checked: active.checked,
            selectionStart: active.selectionStart,
            selectionEnd: active.selectionEnd,
            selectionDirection: active.selectionDirection
        };
    }

    function captureElementScroll(root) {
        var state = [];
        var elements = root.querySelectorAll("[id]");

        for (var index = 0; index < elements.length; index++) {
            if (elements[index].scrollTop !== 0 || elements[index].scrollLeft !== 0) {
                state.push({
                    selector: "#" + cssEscape(elements[index].id),
                    top: elements[index].scrollTop,
                    left: elements[index].scrollLeft
                });
            }
        }

        return state;
    }

    function restoreUpdateState(root, state, preserveFocusedValue) {
        restoreFocus(root, state.focus, preserveFocusedValue);
        restoreElementScroll(root, state.elementScroll);
        window.scrollTo(state.scroll.x, state.scroll.y);
    }

    function restoreFocus(root, state, preserveFocusedValue) {
        if (state === null || state.selector === null) {
            return;
        }

        var element = root.querySelector(state.selector);
        if (!element || !isFocusableStateElement(element)) {
            return;
        }

        if (preserveFocusedValue) {
            if (element.type !== "file") {
                element.value = state.value;
            }

            if (typeof state.checked === "boolean") {
                element.checked = state.checked;
            }
        }

        element.focus({ preventScroll: true });

        if (canSelect(element) && state.selectionStart !== null && state.selectionEnd !== null) {
            element.setSelectionRange(state.selectionStart, state.selectionEnd, state.selectionDirection || "none");
        }
    }

    function restoreElementScroll(root, state) {
        for (var index = 0; index < state.length; index++) {
            var element = root.querySelector(state[index].selector);
            if (element) {
                element.scrollTop = state[index].top;
                element.scrollLeft = state[index].left;
            }
        }
    }

    function isFocusableStateElement(element) {
        return element.matches("input, textarea, select");
    }

    function canSelect(element) {
        return element.matches("textarea, input[type=\"text\"], input[type=\"search\"], input[type=\"url\"], " +
            "input[type=\"tel\"], input[type=\"password\"], input[type=\"email\"], input[type=\"number\"]");
    }

    function stableSelector(element) {
        if (element.id) {
            return "#" + cssEscape(element.id);
        }

        if (element.name) {
            return element.tagName.toLowerCase() + "[name=\"" + attributeEscape(element.name) + "\"]";
        }

        return null;
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === "function") {
            return window.CSS.escape(value);
        }

        return value.replace(/[^a-zA-Z0-9_-]/g, "\\$&");
    }

    function attributeEscape(value) {
        return String(value).replace(/\\/g, "\\\\").replace(/"/g, "\\\"");
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta !== null) {
            return meta.getAttribute("content") || "";
        }

        var input = document.querySelector('input[name="_token"]');
        return input === null ? "" : input.value || "";
    }

    function request(method, url, body, done, fail, headers) {
        var xhr = new XMLHttpRequest();

        xhr.open(method, url, true);
        xhr.setRequestHeader("X-Requested-With", "Atom");
        headers = headers || {};
        var token = csrfToken();
        if (!/^(GET|HEAD|OPTIONS)$/i.test(method) && token !== "" && !headers["X-CSRF-Token"]) {
            headers["X-CSRF-Token"] = token;
        }
        Object.keys(headers).forEach(function (name) {
            xhr.setRequestHeader(name, headers[name]);
        });
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            if (xhr.status >= 200 && xhr.status < 500) {
                var responseUrl = xhr.responseURL || url;
                var uri = xhr.getResponseHeader("X-Atom-Uri");
                if (uri && xhr.status >= 200 && xhr.status < 300) {
                    try {
                        var resolved = new URL(uri, window.location.href);
                        if (resolved.origin === window.location.origin) responseUrl = resolved.href;
                    } catch (error) {
                        // Ignore an invalid URL hint and keep the actual response URL.
                    }
                }
                done(xhr.responseText, responseUrl);
                return;
            }

            fail();
        };
        xhr.onerror = fail;
        xhr.send(body);
    }

    function parsePage(html) {
        var page = document.implementation.createHTMLDocument("");
        page.documentElement.innerHTML = html;

        return page;
    }

    function updatePage(html, url, options) {
        var next = parsePage(html);
        var position = contentScroll();
        var destination = new URL(url || window.location.href, window.location.href);

        if (url && window.location.href !== destination.href) {
            scrollPositions.set(activeEntry, position);
            window.history.replaceState(Object.assign({}, window.history.state, { atomScroll: position }), "");
            activeEntry = newEntryKey();
            window.history.pushState({ atomEntry: activeEntry }, "", destination.href);
        }
        renderedUrl = destination;
        document.title = next.title;
        syncState(next);
        updateDocument(document.body, next.body, options);

        if (options && options.destinationScroll) {
            scrollToDestination(destination);
        } else {
            applyContentScroll((options && options.scrollPosition) || position);
        }
    }

    function updateDocument(current, next, options) {
        var currentRoot = updateRoot(current);
        var nextRoot = updateRoot(next);

        if (currentRoot !== null && nextRoot !== null) {
            window.Atom.update(currentRoot, nextRoot, options);
            return;
        }

        window.Atom.update(current, next, options);
    }

    function updateRoot(root) {
        return root.querySelector("[" + updateRootAttribute.replace(":", "\\:") + "]");
    }

    function syncState(next) {
        var current = document.querySelector('meta[name="atom-state"]');
        var incoming = next.querySelector('meta[name="atom-state"]');

        if (incoming === null) {
            current && current.remove();
            return;
        }

        if (current === null) {
            document.head.appendChild(incoming.cloneNode(true));
            return;
        }

        current.setAttribute("content", incoming.getAttribute("content") || "");
    }

    function setBusy(busy) {
    }

    function showError(message) {
        var response = document.querySelector(".response-preview pre");

        if (response !== null) {
            response.textContent = message;
        }
    }

    function sameOrigin(url) {
        return new URL(url, window.location.href).origin === window.location.origin;
    }

    function enhancedLink(anchor) {
        if (anchor.target !== "" || anchor.hasAttribute("download")) {
            return false;
        }

        var url = new URL(anchor.href, window.location.href);

        return anchor.hasAttribute(navigateAttribute) && sameOrigin(url.href);
    }

    function formAction(form, submitter) {
        if (submitter !== null && submitter.hasAttribute(actionAttribute)) {
            return submitter.getAttribute(actionAttribute) || "";
        }

        if (form.hasAttribute(submitAttribute)) {
            return form.getAttribute(submitAttribute) || "";
        }

        return form.getAttribute(actionAttribute) || "";
    }

    function currentState() {
        var meta = document.querySelector('meta[name="atom-state"]');

        return meta === null ? "" : meta.getAttribute("content") || "";
    }

    function submitForm(form, submitter) {
        if (submittingForms.has(form)) {
            return;
        }

        var wasDisabled = submitter !== null && submitter.disabled;
        var settled = false;
        submittingForms.add(form);
        setBusy(true);

        function settle(callback) {
            if (settled) {
                return;
            }
            settled = true;
            if (submitter !== null) {
                submitter.disabled = wasDisabled;
            }
            try {
                callback();
            } finally {
                submittingForms.delete(form);
                setBusy(false);
            }
        }

        function fail() {
            settle(function () {
                showError("Request failed before a response was received.");
            });
        }

        try {
            var url = form.action || window.location.href;
            var method = (form.getAttribute("method") || "GET").toUpperCase();
            var body = method === "GET" ? null : new FormData(form, submitter);
            var action = formAction(form, submitter);

            if (action !== "" && body !== null) {
                if (submitter !== null && submitter.hasAttribute(actionAttribute)) {
                    body.set("_action", action);
                } else if (!body.has("_action")) {
                    body.append("_action", action);
                }
            }

            if (body !== null && !body.has("_state")) {
                body.append("_state", currentState());
            }

            if (submitter !== null) {
                submitter.disabled = true;
            }

            request(method, url, body, function (html, responseUrl) {
                settle(function () {
                    var active = document.activeElement;
                    updatePage(html, responseUrl, {
                        preserveFocusedValue: !active || active.form !== form
                    });
                });
            }, fail);
        } catch (error) {
            fail();
            throw error;
        }
    }

    function invokeAction(action, target) {
        var body = new FormData();

        body.append("_action", action);
        body.append("_state", currentState());

        setBusy(true);
        target && (target.disabled = true);

        request(
            "POST",
            window.location.href,
            body,
            function (html, responseUrl) {
                updatePage(html, responseUrl);
                setBusy(false);
            },
            function () {
                setBusy(false);
                target && (target.disabled = false);
                showError("Action failed before a response was received.");
            }
        );
    }

    function fieldValue(field) {
        if (field.type === "checkbox") {
            return field.checked ? (field.value || "1") : "";
        }

        if (field.type === "radio") {
            return field.checked ? field.value : "";
        }

        if (field.multiple && field.options) {
            var values = [];

            for (var index = 0; index < field.options.length; index++) {
                if (field.options[index].selected) {
                    values.push(field.options[index].value);
                }
            }

            return values.join(",");
        }

        return field.value === undefined ? "" : field.value;
    }

    function eventFormData(field) {
        var form = field.form || (field.closest ? field.closest("form") : null);
        var body = form === null ? new FormData() : new FormData(form);
        var name = field.getAttribute("name") || "";

        if (form === null && name !== "") {
            body.append(name, fieldValue(field));
        }

        return body;
    }

    function invokeEventAction(action, field, eventName) {
        var body = eventFormData(field);
        var name = field.getAttribute("name") || "";
        var value = fieldValue(field);

        if (!body.has("_action")) {
            body.append("_action", action);
        }

        if (!body.has("_state")) {
            body.append("_state", currentState());
        }

        setBusy(true);

        request(
            "POST",
            window.location.href,
            body,
            function (html, responseUrl) {
                updatePage(html, responseUrl);
                setBusy(false);
            },
            function () {
                setBusy(false);
                showError("Action failed before a response was received.");
            },
            {
                "X-Atom-Intent": "action",
                "X-Atom-Event": eventName,
                "X-Atom-Field": name,
                "X-Atom-Value": value
            }
        );
    }

    function debounceInputAction(action, field) {
        if (field.__atomInputTimer) {
            window.clearTimeout(field.__atomInputTimer);
        }

        field.__atomInputTimer = window.setTimeout(function () {
            field.__atomInputTimer = null;
            invokeEventAction(action, field, "input");
        }, inputDebounceMs);
    }

    function togglePassword(button) {
        var fieldId = button.getAttribute("aria-controls") || "";
        var field = fieldId === "" ? null : document.getElementById(fieldId);

        if (field === null || !field.hasAttribute("data-atom-password-input")) {
            return;
        }

        var reveal = field.type === "password";
        var label = button.getAttribute(reveal ? "data-hide-label" : "data-show-label") || "";

        field.type = reveal ? "text" : "password";
        button.setAttribute("aria-pressed", reveal ? "true" : "false");

        if (label !== "") {
            button.textContent = label;
            button.setAttribute("aria-label", label);
        }
    }

    function updateNavigation(html, url, options) {
        // Filter fields reflect the destination URL, including on Back or Forward.
        var active = document.activeElement;
        options.preserveFocusedValue = !(active && active.form && active.form.hasAttribute(navigateAttribute));
        updatePage(html, url, options);
    }

    function capturePreservedFields() {
        var forms = document.querySelectorAll("form[id][atom\\:preserve-state]");
        var fields = [];
        forms.forEach(function (form) {
            if (form.hasAttribute(navigateAttribute)) return;

            Array.from(form.elements).forEach(function (field) {
                if (!field.id || !isFocusableStateElement(field) || /^(file|hidden|button|submit|reset|image)$/.test(field.type)) return;
                fields.push({
                    formId: form.id,
                    id: field.id,
                    type: field.type,
                    value: field.value,
                    checked: field.checked,
                    selected: field.multiple && field.options ? Array.from(field.options, function (option) {
                        return option.selected;
                    }) : null
                });
            });
        });
        return fields;
    }

    function restorePreservedFields(fields) {
        fields.forEach(function (state) {
            var field = document.getElementById(state.id);
            if (!field || field.type !== state.type || !field.form || field.form.id !== state.formId
                || !field.form.hasAttribute(preserveStateAttribute)) return;

            if (state.selected !== null) {
                Array.from(field.options).forEach(function (option, index) {
                    option.selected = state.selected[index] === true;
                });
            } else {
                field.value = state.value;
            }
            if (typeof state.checked === "boolean") field.checked = state.checked;
        });
    }

    function navigate(href, preserveState, preserveScroll) {
        var url = new URL(href, window.location.href);
        var sequence = ++navigationSequence;
        if (url.hash && url.pathname === renderedUrl.pathname && url.search === renderedUrl.search
            && url.origin === renderedUrl.origin) {
            if (url.href === window.location.href) {
                scrollToDestination(url);
            } else {
                window.location.hash = url.hash;
            }
            return;
        }

        var body = null;
        var headers = null;
        if (preserveState) {
            body = new FormData();
            body.append("_state", currentState());
            headers = {
                "X-Atom-Intent": "navigate",
                "X-Atom-Method": "GET"
            };
        }

        setBusy(true);
        request(
            preserveState ? "POST" : "GET",
            url.href,
            body,
            function (html, responseUrl) {
                if (sequence !== navigationSequence) return;
                var destination = new URL(responseUrl, url);
                if (!destination.hash) destination.hash = url.hash;
                var fields = preserveState && destination.pathname === renderedUrl.pathname ? capturePreservedFields() : [];
                updateNavigation(html, destination.href, { destinationScroll: !!destination.hash || !preserveScroll });
                restorePreservedFields(fields);
                setBusy(false);
            },
            function () {
                if (sequence !== navigationSequence) return;
                setBusy(false);
                window.location.href = url.href;
            },
            headers
        );
    }

    document.addEventListener("submit", function (event) {
        var form = event.target;

        if (!form || form.tagName !== "FORM" || !isEnhancedForm(form)) {
            return;
        }

        if (typeof window.XMLHttpRequest !== "function" || typeof window.FormData !== "function") {
            return;
        }

        event.preventDefault();
        event.__atomHandled = true;
        submitForm(form, event.submitter || null);
    }, true);

    function isEnhancedForm(form) {
        return form.hasAttribute(actionAttribute) || form.hasAttribute(submitAttribute);
    }

    function getFormNavigationUrl(form, submitter) {
        var method = submitter && submitter.hasAttribute("formmethod") ? submitter.formMethod : form.getAttribute("method");
        if ((method || "get").toLowerCase() !== "get") return null;

        var baseTarget = document.querySelector("base[target]");
        var target = submitter && submitter.hasAttribute("formtarget") ? submitter.formTarget : form.getAttribute("target");
        if (target === null && baseTarget) target = baseTarget.target;
        if (target && target.toLowerCase() !== "_self") return null;

        var action = submitter && submitter.hasAttribute("formaction") ? submitter.getAttribute("formaction") : form.getAttribute("action");
        var url;
        try {
            url = new URL(action || window.location.href, document.baseURI);
        } catch (error) {
            return null;
        }
        if (!/^https?:$/.test(url.protocol) || url.origin !== window.location.origin) return null;

        var query = new URLSearchParams();
        new FormData(form, submitter).forEach(function (value, name) {
            // URL-encoded forms send filenames and use CRLF for line breaks.
            var text = typeof value === "string" ? value : value.name;
            query.append(name.replace(/\r\n|\r|\n/g, "\r\n"), text.replace(/\r\n|\r|\n/g, "\r\n"));
        });
        url.search = "?" + query.toString();
        return url;
    }

    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (event.defaultPrevented || form.tagName !== "FORM" || !form.hasAttribute(navigateAttribute)
            || typeof window.XMLHttpRequest !== "function" || typeof window.FormData !== "function") return;

        var url = getFormNavigationUrl(form, event.submitter || null);
        if (url === null) return;

        event.preventDefault();
        navigate(url.href, form.hasAttribute(preserveStateAttribute), form.hasAttribute(preserveScrollAttribute));
    });

    document.addEventListener("click", function (event) {
        if (event.__atomHandled) {
            return;
        }

        var passwordToggle = event.target.closest ? event.target.closest("[data-atom-password-toggle]") : null;

        if (passwordToggle !== null) {
            event.preventDefault();
            togglePassword(passwordToggle);
            return;
        }

        var element = event.target.closest ? event.target.closest("[" + actionAttribute.replace(":", "\\:") + "]") : null;

        if (element === null || element.tagName === "FORM") {
            return;
        }

        if (
            element.form !== undefined &&
            element.form !== null &&
            (element.type === "submit" || element.type === "image")
        ) {
            return;
        }

        if (typeof window.XMLHttpRequest !== "function" || typeof window.FormData !== "function") {
            return;
        }

        var action = element.getAttribute(actionAttribute) || "";
        if (action === "") {
            return;
        }

        event.preventDefault();
        invokeAction(action, element);
    }, true);

    document.addEventListener("change", function (event) {
        var element = event.target.closest ? event.target.closest("[" + changeAttribute.replace(":", "\\:") + "]") : null;

        if (element === null) {
            return;
        }

        if (typeof window.XMLHttpRequest !== "function" || typeof window.FormData !== "function") {
            return;
        }

        var action = element.getAttribute(changeAttribute) || "";
        if (action === "") {
            return;
        }

        invokeEventAction(action, element, "change");
    }, true);

    document.addEventListener("input", function (event) {
        var element = event.target.closest ? event.target.closest("[" + inputAttribute.replace(":", "\\:") + "]") : null;

        if (element === null) {
            return;
        }

        if (typeof window.XMLHttpRequest !== "function" || typeof window.FormData !== "function") {
            return;
        }

        var action = element.getAttribute(inputAttribute) || "";
        if (action === "") {
            return;
        }

        debounceInputAction(action, element);
    }, true);

    document.addEventListener("click", function (event) {
        var anchor = event.target.closest ? event.target.closest("a") : null;

        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || anchor === null || !enhancedLink(anchor)) {
            return;
        }

        event.preventDefault();
        navigate(anchor.href, anchor.hasAttribute(preserveStateAttribute), anchor.hasAttribute(preserveScrollAttribute));
    }, true);

    window.history.scrollRestoration = "manual";
    window.addEventListener("popstate", function () {
        var sequence = ++navigationSequence;
        var destination = new URL(window.location.href);
        scrollPositions.set(activeEntry, contentScroll());
        var entry = currentEntry();
        var position = scrollPositions.get(entry) || window.history.state.atomScroll;
        if (destination.pathname === renderedUrl.pathname && destination.search === renderedUrl.search
            && destination.hash !== renderedUrl.hash) {
            activeEntry = entry;
            renderedUrl = destination;
            if (position) applyContentScroll(position);
            else scrollToDestination(destination);
            return;
        }
        setBusy(true);
        request(
            "GET",
            window.location.href,
            null,
            function (html) {
                if (sequence !== navigationSequence) return;
                activeEntry = entry;
                updateNavigation(html, null, { scrollPosition: position, destinationScroll: !position });
                setBusy(false);
            },
            function () {
                setBusy(false);
                if (sequence === navigationSequence) window.location.reload();
            }
        );
    });

    document.documentElement.classList.add("atom-enhanced");

    window.Atom = {
        enhanced: true,
        setUpdateEngine: function (engine) {
            if (engine && typeof engine.update === "function") {
                updateEngine = engine;
            }
        },
        update: function (current, next, options) {
            updateEngine.update(current, next, options);
        },
        action: function (name) {
            invokeAction(name, null);
        },
        navigate: function (url) {
            navigate(url, false, false);
        }
    };

})();
