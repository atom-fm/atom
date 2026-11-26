<?php

declare(strict_types=1);

use Atom\View\Parser\ViewParser;
use Atom\View\Render\HtmlString;
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

$parser = new ViewParser();
$text = '<em>"A & B"</em>';
$escapedText = "&lt;em&gt;&quot;A &amp; B&quot;&lt;/em&gt;";
verify(
    $renderer->renderComponent(NativeText::class, ["text" => $text]) === $escapedText,
    "The native template did not escape text exactly once."
);

$interpolation = $parser->parse('<p>{{ $text }}|{{ $zero }}|{{ $empty }}|{{ $markup }}</p>');
verify(
    $renderer->render($interpolation, [
        "text" => $text,
        "zero" => 0,
        "empty" => null,
        "markup" => new HtmlString("<em>Trusted markup</em>"),
    ]) === "<p>{$escapedText}|0||<em>Trusted markup</em></p>",
    "Text interpolation did not preserve zero, empty output, or explicit markup."
);

$namedVariables = $parser->parse(<<<'ATOM'
<p title="{{ $expression ?? 'Default' }}">{{ $variables['label'] ?? 'None' }} / {{ $expression ?? 'Default' }}</p>
ATOM);
verify(
    $renderer->render($namedVariables, ["expression" => "Provided", "variables" => ["label" => "Nested"]])
        === '<p title="Provided">Nested / Provided</p>',
    "Template data collided with the expression evaluator's local variables."
);
verify(
    $renderer->render($namedVariables) === '<p title="Default">None / Default</p>',
    "The evaluator exposed local variables that were missing from the render data."
);

$attributes = $parser->parse(
    '<LabPanel title="Attributes" {{ $attributes }} data-count="0" aria-label="A &amp; {{ $label }}" />'
);
$panel = $renderer->render($attributes, [
    "attributes" => [
        "data-count" => 42,
        "data-empty" => "",
        "data-null" => null,
        "hidden" => false,
        "inert" => true,
    ],
    "label" => '"B"',
]);
verify(
    str_starts_with($panel, '<section data-count="0" data-empty="" inert aria-label="A &amp; &quot;B&quot;">'),
    "Forwarded attributes did not preserve override order, empty values, or escaping."
);

$quotedInterpolation = $parser->parse(<<<'ATOM'
<p>{{ "A \"}} B" }} / {{ "{$labels["left's"]}}}" }} / {{ 'C \' }} D' }} / {{ <<< 'TEXT'
E '}} F
TEXT
}}</p>
ATOM);
verify(
    $renderer->render($quotedInterpolation, ["labels" => ["left's" => "Left"]])
        === "<p>A &quot;}} B / Left}} / C ' }} D / E '}} F</p>",
    "Text interpolation ended at braces inside a quoted expression."
);

$quotedAttribute = $parser->parse(<<<'ATOM'
<p title="A &amp; {{ 'B \' }} C' }} / {{ $suffix }}"></p>
ATOM);
verify(
    $renderer->render($quotedAttribute, ["suffix" => '"D"']) === '<p title="A &amp; B \' }} C / &quot;D&quot;"></p>',
    "Attribute interpolation ended at braces inside a quoted expression."
);

$adjacentBraces = $parser->parse(<<<'ATOM'
<p title="{{ match (0) { default => match (1) { default => 'A & B' }}}}">{{ match (0) { default => "a}}b" }}}</p>
ATOM);
verify(
    $renderer->render($adjacentBraces) === '<p title="A &amp; B">a}}b</p>',
    "Interpolation consumed a PHP closing brace next to the template delimiter."
);

$fragments = $parser->parse(<<<'ATOM'
<LabPanel title="Fragments">
    <Fragment name="footer">
        @foreach ($items as $label)
            <LabLabel />
        @empty
            <span>No items</span>
        @endforeach
        <LabLabel />
    </Fragment>
</LabPanel>
ATOM);
$populated = $renderer->render($fragments, ["items" => new ArrayIterator(["0", "A & B"]), "label" => "Outer"]);
verify(
    preg_match('~<footer>\s*<strong>0</strong>\s*<strong>A &amp; B</strong>\s*<strong>Outer</strong>\s*</footer>~', $populated) === 1,
    "The footer lost an iterable item or leaked the loop variable into the outer context."
);
$empty = $renderer->render($fragments, ["items" => new ArrayIterator([]), "label" => "Fresh"]);
verify(
    preg_match('~<footer>\s*<span>No items</span>\s*<strong>Fresh</strong>\s*</footer>~', $empty) === 1,
    "The footer did not render its empty branch with fresh context."
);

$cache = new TemplateCache();
$template = $cache->remember($file, fn() => $parser->parseFile($file));
verify($cache->remember($file, fn() => $parser->parseFile($file)) === $template, "The cache did not reuse the parsed template.");
verify(
    str_contains($renderer->render($template, ["label" => "Fresh data"]), "<strong>Fresh data</strong>"),
    "A cached template did not render with fresh input."
);

echo "View Lab checks passed.\n";
