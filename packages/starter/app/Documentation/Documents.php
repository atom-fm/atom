<?php

declare(strict_types=1);

namespace App\Documentation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class Documents
{
    private string $directory;

    public function __construct(private Markdown $markdown)
    {
        $this->directory = dirname(__DIR__, 2) . "/content/docs";
    }

    public function find(string $path): ?Document
    {
        $slug = $this->normalize($path);
        if ($slug === null) {
            return null;
        }

        $file = $this->fileFor($slug);
        return $file === null ? null : $this->read($file);
    }

    /** @return DocumentEntry[] */
    public function entries(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $entries = [];
        $pathsBySlug = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->directory,
            FilesystemIterator::SKIP_DOTS
        ));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== "md") {
                continue;
            }

            $relative = str_replace("\\", "/", substr($file->getPathname(), strlen($this->directory) + 1));
            $slug = trim((string) preg_replace(['#\.md$#i', '#(^|/)index$#'], ["", ""], $relative), "/");
            if (isset($pathsBySlug[$slug])) {
                $url = $slug === "" ? "/docs" : "/docs/{$slug}";
                throw new RuntimeException(
                    "Documentation URL {$url} is shared by '{$pathsBySlug[$slug]}' and '{$relative}'. Rename or move one file."
                );
            }
            $pathsBySlug[$slug] = $relative;

            $source = file_get_contents($file->getPathname());
            $name = $file->getBasename(".md");
            $fallback = $name === "index" && $slug !== "" ? basename($slug) : $name;
            $fallback = ucwords(str_replace(["-", "_"], " ", $fallback));
            $metadata = $this->markdown->frontMatter(is_string($source) ? $source : "");
            $entries[] = new DocumentEntry(
                $slug,
                $this->metadataString($metadata, "title", $fallback),
                $this->metadataString($metadata, "description"),
                is_int($metadata["order"] ?? null) ? $metadata["order"] : 1000
            );
        }

        foreach ($entries as $entry) {
            $separator = strrpos($entry->slug, "/");
            if ($separator === false) {
                continue;
            }

            $parent = substr($entry->slug, 0, $separator);
            if (!isset($pathsBySlug[$parent])) {
                throw new RuntimeException(
                    "Documentation file '{$pathsBySlug[$entry->slug]}' has no parent page for /docs/{$parent}. "
                    . "Add '{$parent}/index.md' or '{$parent}.md' so it appears in navigation."
                );
            }
        }

        usort($entries, static fn(DocumentEntry $left, DocumentEntry $right): int =>
            [$left->order, $left->slug] <=> [$right->order, $right->slug]
        );
        return $entries;
    }

    private function normalize(string $path): ?string
    {
        $slug = trim(str_replace("\\", "/", $path), "/");
        if ($slug === "") {
            return "";
        }

        return preg_match('/^[a-z0-9]+(?:[\/-][a-z0-9]+)*$/', $slug) === 1 ? $slug : null;
    }

    private function fileFor(string $slug): ?string
    {
        $relative = $slug === "" ? "index.md" : "{$slug}.md";
        $file = $this->directory . "/" . $relative;
        if (is_file($file)) {
            return $file;
        }

        $index = $slug === "" ? $file : $this->directory . "/{$slug}/index.md";
        return is_file($index) ? $index : null;
    }

    private function read(string $file): Document
    {
        $source = file_get_contents($file);
        $relative = str_replace("\\", "/", substr($file, strlen($this->directory) + 1));
        $slug = preg_replace('/\.md$/i', "", $relative) ?? $relative;
        $slug = preg_replace('#(^|/)index$#', "", $slug) ?? $slug;
        $slug = trim($slug, "/");
        $name = pathinfo($file, PATHINFO_FILENAME);
        $fallback = $name === "index" && $slug !== "" ? basename($slug) : $name;
        $fallback = ucwords(str_replace(["-", "_"], " ", $fallback));

        return $this->markdown->render(is_string($source) ? $source : "", $slug, $fallback);
    }

    /** @param array<array-key, mixed> $metadata */
    private function metadataString(array $metadata, string $key, string $fallback = ""): string
    {
        $value = $metadata[$key] ?? null;
        return is_string($value) && trim($value) !== "" ? trim($value) : $fallback;
    }
}
