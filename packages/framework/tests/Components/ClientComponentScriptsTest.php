<?php

declare(strict_types=1);

namespace Atom\Tests\Components;

use Atom\Modules\Client\Client;
use Atom\Modules\Client\ClientScripts;
use PHPUnit\Framework\TestCase;

final class ClientComponentScriptsTest extends TestCase
{
    public function testComponentsAreOptIn(): void
    {
        $scripts = new ClientScripts();
        $this->assertStringNotContainsString("atom-controls.js", $scripts->render());

        $scripts->components = true;
        $this->assertSame("1", Client::COMPONENTS_VERSION);
        $this->assertStringContainsString(
            '<script type="module" src="/atom/client/resources/atom-controls.js?v=1"></script>',
            $scripts->render()
        );
        $this->assertStringContainsString('src="/atom/client/resources/atom-morphdom.js?v=6"', $scripts->render());
    }

    public function testComponentsUseConfiguredResourcePath(): void
    {
        $scripts = new ClientScripts();
        $scripts->components = true;
        $scripts->resourcePath = "/assets/client/";
        $this->assertStringContainsString('src="/assets/client/atom-controls.js?v=1"', $scripts->render());
    }
}
