<?php

declare(strict_types=1);

namespace App\Documentation;

use RuntimeException;

final readonly class DocumentIndex
{
    private string $file;

    public function __construct()
    {
        $this->file = dirname(__DIR__, 2) . "/storage/docs-index.json";
    }

    /** @return DocumentEntry[] */
    public function all(): array
    {
        if (!is_file($this->file)) {
            throw new RuntimeException("Documentation index is missing. Run php atom docs:index.");
        }

        $json = file_get_contents($this->file);
        $rows = json_decode(is_string($json) ? $json : "", true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($rows)) {
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entries[] = new DocumentEntry(
                $this->string($row, "slug"),
                $this->string($row, "title", "Untitled"),
                $this->string($row, "description"),
                $this->integer($row, "order")
            );
        }

        return $entries;
    }

    /** @param DocumentEntry[] $entries */
    public function write(array $entries): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create documentation index directory {$directory}.");
        }

        $rows = array_map(static fn(DocumentEntry $entry): array => [
            "slug" => $entry->slug,
            "title" => $entry->title,
            "description" => $entry->description,
            "order" => $entry->order,
        ], $entries);
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = $this->file . ".tmp";

        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->file)) {
            throw new RuntimeException("Unable to write documentation index to {$this->file}.");
        }
    }

    /** @param array<array-key, mixed> $row */
    private function string(array $row, string $key, string $fallback = ""): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : $fallback;
    }

    /** @param array<array-key, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        return is_int($value) ? $value : 1000;
    }
}
