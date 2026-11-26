<?php

declare(strict_types=1);

namespace Atom\Tests\View;

use Atom\View\Component\ComponentRegistry;
use Atom\View\Style\ComponentStyleScanner;
use Atom\View\Style\StyleComposer;
use PHPUnit\Framework\TestCase;

final class ComponentStylesTest extends TestCase
{
    public function testRejectsRelativeAssets(): void
    {
        $file = __DIR__ . "/StyledTestComponent.css";
        $registry = new ComponentRegistry();
        $registry->register("Test", StyledTestComponent::class);
        $scanner = new ComponentStyleScanner();
        $scanner->addRegistry($registry);
        try {
            file_put_contents($file, '.test { background: url("image.png"); }');
            $this->expectException(\RuntimeException::class);
            (new StyleComposer($scanner))->compose();
        } finally {
            if (is_file($file)) { unlink($file); }
        }
    }

    public function testDiscoversDeduplicatesAndRecomposesAdjacentStyles(): void
    {
        $file = __DIR__ . "/StyledTestComponent.css";
        $destination = sys_get_temp_dir() . "/atom-style-" . bin2hex(random_bytes(8)) . ".css";
        $registry = new ComponentRegistry();
        $registry->register("Test", StyledTestComponent::class);
        $scanner = new ComponentStyleScanner();
        $scanner->addRegistry($registry);
        $scanner->addRegistry($registry);
        $composer = new StyleComposer($scanner);
        try {
            file_put_contents($file, ".test { color: red; }");
            self::assertSame(".test { color: red; }\n", $composer->compose());
            file_put_contents($file, ".test { color: blue; }");
            $composer->build($destination);
            self::assertSame($composer->compose(), file_get_contents($destination));
        } finally {
            if (is_file($file)) { unlink($file); }
            if (is_file($destination)) { unlink($destination); }
        }
    }
}

final class StyledTestComponent implements \Atom\View\Component\ComponentInterface
{
    public function render(): string
    {
        return "";
    }
}
