<?php

declare(strict_types=1);

use Atom\View\Parser\ViewParser;
use Atom\View\TemplateCache;
use ViewLab\NativeText;
use ViewLab\Panel;

$renderer = require __DIR__ . "/bootstrap.php";

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$lock = json_decode(file_get_contents(__DIR__ . "/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
verify(array_column($lock["packages"], "name") === ["atom-fm/view"], "View must be the sole runtime package.");

$file = __DIR__ . "/views/preview.atom.html";
$preview = $renderer->renderFile($file, ["label" => "A & B"]);
foreach ([
    '<section data-example="panel">',
    "<h2>Component composition</h2>",
    "<strong>A &amp; B</strong>",
    "<strong>Explicit label</strong>",
    "<footer>Rendered with Atom View</footer>",
] as $expected) {
    verify(str_contains($preview, $expected), "Missing preview markup: {$expected}");
}

$standalonePanel = $renderer->renderComponent(Panel::class, ["title" => "Standalone panel"]);
verify(str_contains($standalonePanel, "<h2>Standalone panel</h2>"), "The panel did not render by class.");

verify($renderer->renderComponent(NativeText::class, ["text" => "0"]) === "0", "Native template output lost the zero.");
verify($renderer->renderComponent(NativeText::class, ["text" => ""]) === "", "Empty native template output changed.");

$cache = new TemplateCache();
$parse = (new ViewParser())->parseFile(...);
$template = $cache->remember($file, fn() => $parse($file));
verify($cache->remember($file, fn() => $parse($file)) === $template, "The cache did not reuse the parsed template.");
verify(
    str_contains($renderer->render($template, ["label" => "Fresh data"]), "<strong>Fresh data</strong>"),
    "A cached template did not render with fresh input."
);

echo "View Lab checks passed.\n";
