<?php

declare(strict_types=1);

namespace Atom\Scheduler;

use Atom\Support\Paths;
use RuntimeException;

/** @internal */
final readonly class ScheduleTaskLocks
{
    public function __construct(private Paths $paths)
    {
    }

    /** @return resource|null The caller owns the open lock file. */
    public function acquire(string $key)
    {
        $directory = $this->paths->resolve("@root/storage/scheduler");
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the scheduler lock directory.");
        }

        $file = @fopen($directory . "/" . hash("sha256", $key) . ".lock", "c+b");
        if ($file === false) {
            throw new RuntimeException("Cannot open the scheduler lock for '{$key}'.");
        }
        if (flock($file, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return $file;
        }

        fclose($file);
        if ($wouldBlock) {
            return null;
        }
        throw new RuntimeException("Cannot acquire the scheduler lock for '{$key}'.");
    }
}
