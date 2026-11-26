<?php

declare(strict_types=1);

namespace Atom\Tests\Components;

use Atom\Components\ComponentsScripts;
use Atom\Modules\Client\ClientScripts;
use PHPUnit\Framework\TestCase;

final class ComponentsScriptsTest extends TestCase
{
    public function testClientAndComponentsLoadTheirOwnScripts(): void
    {
        $client = new ClientScripts();
        $client->morphdom = true;
        $this->assertStringContainsString('src="/atom/client/resources/atom-morphdom.js?v=6"', $client->render());
        $this->assertStringNotContainsString("atom-controls.js", $client->render());

        $this->assertSame(
            '<script type="module" src="/atom/components/resources/atom-controls.js?v=2"></script>',
            (new ComponentsScripts())->render()
        );
    }

    public function testComponentsUseConfiguredResourcePath(): void
    {
        $scripts = new ComponentsScripts();
        $scripts->resourcePath = "/assets/widgets/";
        $this->assertStringContainsString('src="/assets/widgets/atom-controls.js?v=2"', $scripts->render());
    }
}
