<?php

declare(strict_types=1);

namespace Atom\View\Style;

use Atom\View\Component\ComponentRegistry;
use ReflectionClass;

final class ComponentStyleScanner
{
    /** @var ComponentRegistry[] */
    private array $registries = [];

    public function addRegistry(ComponentRegistry $registry): void
    {
        $this->registries[] = $registry;
    }

    /** @return list<string> */
    public function scan(): array
    {
        $files = [];
        foreach ($this->registries as $registry) {
            foreach ($registry->own() as $class) {
                $reflection = new ReflectionClass($class);
                $source = $reflection->getFileName();
                if ($source === false) {
                    continue;
                }
                $path = dirname($source) . "/" . $reflection->getShortName() . ".css";
                $resolved = realpath($path);
                if ($resolved !== false && is_file($resolved)) {
                    $files[$resolved] = $resolved;
                }
            }
        }
        $files = array_values($files);
        sort($files, SORT_STRING);
        return $files;
    }
}
