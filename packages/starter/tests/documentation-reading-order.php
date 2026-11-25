<?php

declare(strict_types=1);

use App\Documentation\DocumentEntry;
use App\Documentation\DocumentIndex;
use App\Documentation\Documents;
use App\Documentation\Markdown;
use App\Pages\DocsPage;

require dirname(__DIR__) . "/vendor/autoload.php";

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Use an isolated index with deliberately interleaved order values while loading
// real starter documents. No local content or navigation metadata is modified.
$root = sys_get_temp_dir() . "/atom-docs-order-" . bin2hex(random_bytes(8));
$sourceDirectory = $root . "/app/Documentation";
mkdir($sourceDirectory, 0775, true);
$sourceFile = $sourceDirectory . "/DocumentIndex.php";
copy(dirname(__DIR__) . "/app/Documentation/DocumentIndex.php", $sourceFile);

try {
    require $sourceFile;
    $index = new DocumentIndex();
    $index->write([
        new DocumentEntry("", "Documentation", "", 0),
        new DocumentEntry("concepts/templates", "Templates", "", 1),
        new DocumentEntry("advanced/architecture/request-lifecycle", "Request lifecycle", "", 1),
        new DocumentEntry("guides/create-a-page", "Create a page", "", 2),
        new DocumentEntry("advanced/architecture", "Architecture", "", 5),
        new DocumentEntry("guides", "Guides", "", 10),
        new DocumentEntry("concepts", "Concepts", "", 20),
        new DocumentEntry("advanced", "Advanced", "", 30),
    ]);
    $documents = new Documents(new Markdown());
    $expected = ["", "guides", "guides/create-a-page", "concepts", "concepts/templates",
        "advanced", "advanced/architecture", "advanced/architecture/request-lifecycle"];

    foreach ($expected as $position => $slug) {
        $page = new DocsPage();
        check($page->get($documents, $index, $slug) === null, "Fixture document must exist: {$slug}");
        check($page->previous?->slug === ($expected[$position - 1] ?? null), "Incorrect previous document for {$slug}");
        check($page->next?->slug === ($expected[$position + 1] ?? null), "Incorrect next document for {$slug}");

        $dom = new DOMDocument();
        $dom->loadHTML((string) $page->navigation(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $hrefs = [];
        foreach ((new DOMXPath($dom))->query('//a') as $link) {
            $hrefs[] = $link->getAttribute("href");
        }
        $expectedUrls = array_map(static fn(string $path): string => $path === "" ? "/docs" : "/docs/{$path}", $expected);
        check($hrefs === $expectedUrls, "Pagination and sidebar must share the same reading order.");
    }

    $index->write([new DocumentEntry("concepts", "Concepts", "", 0)]);
    $page = new DocsPage();
    $page->get($documents, $index, "concepts");
    check($page->previous === null && $page->next === null, "A single indexed page must have no neighbors.");
    $page->get($documents, $index, "concepts/templates");
    check($page->previous === null && $page->next === null, "An unindexed document must have no neighbors.");

    echo "Documentation reading order checks passed." . PHP_EOL;
} finally {
    foreach ([$root . "/storage/docs-index.json.tmp", $root . "/storage/docs-index.json", $sourceFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$root . "/storage", $sourceDirectory, $root . "/app", $root] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
