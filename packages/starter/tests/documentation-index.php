<?php

declare(strict_types=1);

use App\Commands\DocumentationCommands;
use App\Documentation\DocumentEntry;
use App\Documentation\DocumentIndex;
use App\Documentation\Documents;
use App\Documentation\Markdown;
use Atom\Console\ConsoleOutput;

require dirname(__DIR__) . "/vendor/autoload.php";

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Load the real implementation from an isolated application tree so its default
// storage path exercises a fresh installation without altering local data.
$root = sys_get_temp_dir() . "/atom-docs-index-" . bin2hex(random_bytes(8));
$sourceDirectory = $root . "/app/Documentation";
mkdir($sourceDirectory, 0775, true);
$sourceFile = $sourceDirectory . "/DocumentIndex.php";
copy(dirname(__DIR__) . "/app/Documentation/DocumentIndex.php", $sourceFile);
$documentsFile = $sourceDirectory . "/Documents.php";
copy(dirname(__DIR__) . "/app/Documentation/Documents.php", $documentsFile);
$docsDirectory = $root . "/content/docs";

try {
    require $sourceFile;
    $index = new DocumentIndex();

    try {
        $index->all();
        throw new RuntimeException("A missing index must provide setup instructions.");
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), "Run php atom docs:index"),
            "A missing index must explain the command needed to generate it.");
    }

    $entries = [
        new DocumentEntry("", "Documentation", "Start here", 0),
        new DocumentEntry("guides/pages", "Pages & templates", "Create a page", 20),
    ];
    check(!is_dir($root . "/storage"), "The fixture must start without storage.");
    $index->write($entries);
    check(is_file($root . "/storage/docs-index.json"), "Indexing must create missing storage.");
    check($index->all() == $entries, "Indexing must preserve all navigation metadata.");
    check(!is_file($root . "/storage/docs-index.json.tmp"), "Indexing must finish its atomic rename.");

    $index->write([$entries[1]]);
    check($index->all() == [$entries[1]], "Regeneration must replace stale entries.");
    $index->write([]);
    check($index->all() === [], "An empty content tree must produce a readable empty index.");

    require $documentsFile;
    mkdir($docsDirectory . "/guides", 0775, true);
    file_put_contents($docsDirectory . "/guides.md", "# Standalone guides page\n");
    $command = new DocumentationCommands(new Documents(new Markdown()), $index, new ConsoleOutput());
    check($command->index() === 0, "An unambiguous docs tree must index successfully.");
    $previousIndex = file_get_contents($root . "/storage/docs-index.json");

    file_put_contents($docsDirectory . "/guides/index.md", "# Guides section landing page\n");
    try {
        $command->index();
        throw new LogicException("Indexing must fail when two source files share a URL.");
    } catch (RuntimeException $exception) {
        foreach (["/docs/guides", "'guides.md'", "'guides/index.md'"] as $detail) {
            check(str_contains($exception->getMessage(), $detail), "Collision error must include {$detail}.");
        }
    }
    check(file_get_contents($root . "/storage/docs-index.json") === $previousIndex,
        "A failed index build must preserve the last valid index.");

    unlink($docsDirectory . "/guides/index.md");
    mkdir($docsDirectory . "/guides/deep", 0775, true);
    file_put_contents($docsDirectory . "/guides/deep/page.md", "# Nested page\n");
    try {
        $command->index();
        throw new LogicException("Indexing must reject pages hidden by a missing parent.");
    } catch (RuntimeException $exception) {
        foreach (["guides/deep/page.md", "/docs/guides/deep", "guides/deep/index.md"] as $detail) {
            check(str_contains($exception->getMessage(), $detail), "Missing-parent error must include {$detail}.");
        }
    }
    check(file_get_contents($root . "/storage/docs-index.json") === $previousIndex,
        "Missing parents must not replace the last valid index.");

    file_put_contents($docsDirectory . "/guides/deep/index.md", "# Nested section\n");
    check($command->index() === 0 && count($index->all()) === 3,
        "A folder index must connect nested pages to a sibling-file parent.");

    echo "Documentation index checks passed." . PHP_EOL;
} finally {
    foreach ([$root . "/storage/docs-index.json.tmp", $root . "/storage/docs-index.json", $sourceFile,
        $documentsFile, $docsDirectory . "/guides.md", $docsDirectory . "/guides/index.md",
        $docsDirectory . "/guides/deep/page.md", $docsDirectory . "/guides/deep/index.md"] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$root . "/storage", $sourceDirectory, $root . "/app", $docsDirectory . "/guides/deep", $docsDirectory . "/guides",
        $docsDirectory, $root . "/content", $root] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
