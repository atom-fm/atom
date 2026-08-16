<?php

declare(strict_types=1);

namespace Atom\Tests\Components;

use Atom\Modules\Components\Icons\IconRegistry;
use Atom\Modules\Components\Icons\IconResolverInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IconRegistryTest extends TestCase
{
    public function testRegistersAndResolvesPrefixes(): void
    {
        $registry = new IconRegistry();
        $registry->register("example", new class implements IconResolverInterface {
            public function resolve(string $name): string
            {
                return "resolved:{$name}";
            }
        });

        $this->assertTrue($registry->has("example"));
        $this->assertSame("resolved:search", $registry->resolve("example", "search"));
    }

    public function testReportsUnregisteredPrefix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Icon prefix 'missing' is not registered.");

        (new IconRegistry())->resolve("missing", "search");
    }
}
