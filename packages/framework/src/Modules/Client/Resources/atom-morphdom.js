(function () {
    if (!window.Atom || typeof window.Atom.setUpdateEngine !== "function" || typeof window.morphdom !== "function") {
        return;
    }

    var transitionAttribute = "atom:transition";
    var leavingAttribute = "data-atom-leaving";
    var exitTimeoutMs = 1000;

    function isActiveFormElement(element) {
        return element === document.activeElement && element.matches("input, textarea, select");
    }

    window.Atom.setUpdateEngine({
        update: function (current, next, options) {
            var preserveFocusedValue = !options || options.preserveFocusedValue !== false;

            window.morphdom(current, next, {
                childrenOnly: true,
                onBeforeElChildrenUpdated: function (fromElement) {
                    return !fromElement[Symbol.for("atom.component")];
                },
                onBeforeElUpdated: function (fromElement, toElement) {
                    cancelExit(fromElement);

                    if (preserveFocusedValue && isActiveFormElement(fromElement)) {
                        copyFormState(fromElement, toElement);
                    }

                    return true;
                },
                onBeforeNodeDiscarded: function (node) {
                    return deferDiscard(node);
                }
            });
        }
    });

    function deferDiscard(node) {
        if (node.nodeType !== 1 || !node.hasAttribute(transitionAttribute)) {
            return true;
        }

        if (node.hasAttribute(leavingAttribute)) {
            return false;
        }

        var active = true;
        var animations = [];
        var frame = null;
        var timeout = null;
        var previousAriaHidden = node.getAttribute("aria-hidden");
        var previousInert = node.inert === true;

        node.setAttribute(leavingAttribute, "");
        node.setAttribute("aria-hidden", "true");
        node.inert = true;

        function finish() {
            if (!active) {
                return;
            }

            active = false;
            clearExitTimers();
            node.__atomCancelExit = null;

            if (node.parentNode !== null) {
                node.parentNode.removeChild(node);
            }
        }

        function cancel() {
            if (!active) {
                return;
            }

            active = false;
            clearExitTimers();

            for (var index = 0; index < animations.length; index++) {
                animations[index].cancel();
            }

            node.removeAttribute(leavingAttribute);
            if (previousAriaHidden === null) {
                node.removeAttribute("aria-hidden");
            } else {
                node.setAttribute("aria-hidden", previousAriaHidden);
            }
            node.inert = previousInert;
            node.__atomCancelExit = null;
        }

        function clearExitTimers() {
            if (frame !== null && typeof window.cancelAnimationFrame === "function") {
                window.cancelAnimationFrame(frame);
            }

            if (timeout !== null) {
                window.clearTimeout(timeout);
            }
        }

        node.__atomCancelExit = cancel;
        frame = nextFrame(function () {
            if (!active) {
                return;
            }

            animations = exitAnimations(node);
            if (animations.length === 0) {
                finish();
                return;
            }

            timeout = window.setTimeout(finish, exitTimeoutMs);
            Promise.all(animations.map(function (animation) {
                return animation.finished.catch(function () {});
            })).then(finish);
        });

        return false;
    }

    function cancelExit(element) {
        if (typeof element.__atomCancelExit === "function") {
            element.__atomCancelExit();
        }
    }

    function exitAnimations(element) {
        if (typeof element.getAnimations !== "function") {
            return [];
        }

        return element.getAnimations({ subtree: true }).filter(function (animation) {
            if (!animation.effect || typeof animation.effect.getTiming !== "function") {
                return true;
            }

            return animation.effect.getTiming().iterations !== Infinity;
        });
    }

    function nextFrame(callback) {
        if (typeof window.requestAnimationFrame === "function") {
            return window.requestAnimationFrame(callback);
        }

        return window.setTimeout(callback, 0);
    }

    function copyFormState(fromElement, toElement) {
        if (fromElement.type !== "file") {
            toElement.value = fromElement.value;
        }

        if (typeof fromElement.checked === "boolean") {
            toElement.checked = fromElement.checked;
        }
    }
})();
