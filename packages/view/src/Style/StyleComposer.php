<?php

declare(strict_types=1);

namespace Atom\View\Style;

use RuntimeException;

final readonly class StyleComposer
{
    public function __construct(private ComponentStyleScanner $scanner)
    {
    }

    public function compose(): string
    {
        $parts = [];
        foreach ($this->scanner->scan() as $file) {
            $css = file_get_contents($file);
            if ($css === false) {
                throw new RuntimeException("Cannot read component stylesheet: {$file}");
            }
            // Bundled CSS must not depend on the location of its source file.
            if (preg_match('/@(?:import|charset)\\b/i', $css) === 1) {
                throw new RuntimeException("Component CSS cannot contain @import or @charset: {$file}");
            }
            preg_match_all('/url\\(\\s*([\\x22\\x27]?)(.*?)\\1\\s*\\)/is', $css, $urls);
            foreach ($urls[2] as $url) {
                if (!preg_match('~^(?:/|\\#|[a-z][a-z0-9+.-]*:)~i', trim($url))) {
                    throw new RuntimeException("Use root-relative or absolute asset URLs in component CSS: {$file}");
                }
            }
            $parts[] = $css;
        }
        return implode("\n", $parts) . ($parts === [] ? "" : "\n");
    }

    public function build(string $destination): void
    {
        $css = $this->compose();
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create stylesheet directory: {$directory}");
        }
        $temporary = tempnam($directory, "atom-css-");
        if ($temporary === false) {
            throw new RuntimeException("Cannot create temporary stylesheet.");
        }
        try {
            if (file_put_contents($temporary, $css) !== strlen($css) || !rename($temporary, $destination)) {
                throw new RuntimeException("Cannot write stylesheet: {$destination}");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
