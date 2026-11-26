<?php

declare(strict_types=1);

namespace Atom\Tests\Client;

use PHPUnit\Framework\TestCase;

final class ClientResourcesTest extends TestCase
{
    public function testClientJavascriptAllowsPageActionsInsideForms(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . "/src/Modules/Client/Resources/atom.js");

        $this->assertIsString($script);
        $this->assertStringContainsString('submitForm(form, event.submitter || null);', $script);
        $this->assertStringContainsString('(element.type === "submit" || element.type === "image")', $script);
        $this->assertStringNotContainsString('element.form !== undefined && element.form !== null) {', $script);
    }

    public function testClientJavascriptSupportsChangeActionsWithFormContext(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . "/src/Modules/Client/Resources/atom.js");

        $this->assertIsString($script);
        $this->assertStringContainsString('var changeAttribute = "atom:change";', $script);
        $this->assertStringContainsString('new FormData(form)', $script);
        $this->assertStringContainsString('"X-Atom-Event": eventName', $script);
        $this->assertStringContainsString('"X-Atom-Field": name', $script);
        $this->assertStringContainsString('meta[name="csrf-token"]', $script);
        $this->assertStringContainsString('input[name="_token"]', $script);
        $this->assertStringContainsString('headers["X-CSRF-Token"] = token;', $script);
        $this->assertStringContainsString('document.addEventListener("change"', $script);
    }

    public function testClientJavascriptSupportsDebouncedInputActionsWithFormContext(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . "/src/Modules/Client/Resources/atom.js");

        $this->assertIsString($script);
        $this->assertStringContainsString('var inputAttribute = "atom:input";', $script);
        $this->assertStringContainsString("var inputDebounceMs = 300;", $script);
        $this->assertStringContainsString("function debounceInputAction(action, field)", $script);
        $this->assertStringContainsString('document.addEventListener("input"', $script);
        $this->assertStringContainsString('invokeEventAction(action, field, "input")', $script);
    }

    public function testMorphdomAdapterDefersOptedInExitTransitions(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . "/src/Modules/Client/Resources/atom-morphdom.js");

        $this->assertIsString($script);
        $this->assertStringContainsString('var transitionAttribute = "atom:transition";', $script);
        $this->assertStringContainsString('onBeforeNodeDiscarded: function (node)', $script);
        $this->assertStringContainsString('node.setAttribute(leavingAttribute, "");', $script);
        $this->assertStringContainsString('element.getAnimations({ subtree: true })', $script);
        $this->assertStringContainsString('node.inert = true;', $script);
        $this->assertStringContainsString('window.setTimeout(finish, exitTimeoutMs)', $script);
        $this->assertStringContainsString('cancelExit(fromElement);', $script);
    }
}
