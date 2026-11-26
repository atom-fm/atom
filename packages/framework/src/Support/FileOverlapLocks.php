<?php

declare(strict_types=1);

namespace Atom\Support;

use RuntimeException;

/** @internal */
final readonly class FileOverlapLocks
{
    public function __construct(private Paths $paths, private string $directory)
    {
    }

    /** @return resource|null The caller owns the open lock file. */
    public function acquire(string $key)
    {
        $directory = $this->paths->resolve($this->directory);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the overlap lock directory.");
        }

        $file = @fopen($directory . "/" . hash("sha256", $key) . ".lock", "c+b");
        if ($file === false) {
            throw new RuntimeException("Cannot open the overlap lock for '{$key}'.");
        }
        if (flock($file, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return $file;
        }

        fclose($file);
        if ($wouldBlock) {
            return null;
        }
        throw new RuntimeException("Cannot acquire the overlap lock for '{$key}'.");
    }
}
