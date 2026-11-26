<?php

declare(strict_types=1);

namespace Atom\Lucide\Tests;

use Atom\Lucide\LucideIconResolver;
use Atom\Lucide\LucideServiceProvider;
use Atom\View\Icons\IconRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LucideIconResolverTest extends TestCase
{
    public function testProviderRegistersTheLucidePrefix(): void
    {
        $icons = new IconRegistry();
        (new LucideServiceProvider())->icons($icons);

        $this->assertTrue($icons->has("lucide"));
        $this->assertStringStartsWith("<svg", $icons->resolve("lucide", "search"));
    }

    public function testResolvesAnIconAsInlineSvg(): void
    {
        $svg = (new LucideIconResolver())->resolve("search");

        $this->assertStringStartsWith("<svg", $svg);
        $this->assertStringContainsString("</svg>", $svg);
    }

    public function testRejectsInvalidAndTraversalNames(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Lucide icon name '../search' is invalid.");

        (new LucideIconResolver())->resolve("../search");
    }

    public function testReportsMissingIcons(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Lucide icon 'definitely-not-an-icon' was not found.");

        (new LucideIconResolver())->resolve("definitely-not-an-icon");
    }
}
