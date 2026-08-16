<?php

declare(strict_types=1);

namespace Atom\Module;

use Atom\View\Component\ComponentRegistry;

final readonly class ModuleScope
{
    public string $id;
    public string $basePath;

    public function __construct(
        public ModuleInterface $module,
        string $basePath,
        public ComponentRegistry $components
    ) {
        $this->basePath = self::normalizeBasePath($basePath);
        $this->id = $module::class . "@" . $this->basePath;
    }

    private static function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath, " /");

        return $basePath === "" ? "/" : "/" . $basePath;
    }
}
