<?php

declare(strict_types=1);

namespace App\Documentation;

final readonly class DocumentEntry
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public int $order
    ) {
    }

    public function url(): string
    {
        return $this->slug === "" ? "/docs" : "/docs/{$this->slug}";
    }

}
