<?php

declare(strict_types=1);

namespace Atom\Scheduler;

use Atom\Support\Paths;
use DateTimeImmutable;
use RuntimeException;

/** @internal */
final readonly class ScheduleMinuteClaims
{
    public function __construct(private Paths $paths)
    {
    }

    public function claim(string $key, DateTimeImmutable $time): bool
    {
        $directory = $this->paths->resolve("@root/storage/scheduler");
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the scheduler claim directory.");
        }

        $path = $directory . "/" . hash("sha256", $key) . ".claim";
        $file = @fopen($path, "c+b");
        if ($file === false) {
            throw new RuntimeException("Cannot open the scheduler claim for '{$key}'.");
        }

        try {
            if (!flock($file, LOCK_EX)) {
                throw new RuntimeException("Cannot lock the scheduler claim for '{$key}'.");
            }

            $timestamp = $time->getTimestamp();
            $minute = intdiv($timestamp, 60);
            if ($timestamp % 60 < 0) {
                $minute--;
            }
            $stored = stream_get_contents($file);
            if ($stored === false || ($stored !== "" && !preg_match('/^[+-][0-9]{20}$/D', $stored))) {
                throw new RuntimeException("Cannot read the scheduler claim for '{$key}'.");
            }
            if ($stored !== "" && (int) $stored >= $minute) {
                return false;
            }

            // Rewrite the fixed-width record while holding the lock on the same file.
            $record = sprintf("%+021d", $minute);
            if (!rewind($file) || fwrite($file, $record) !== strlen($record) || !fflush($file)) {
                throw new RuntimeException("Cannot save the scheduler claim for '{$key}'.");
            }
            return true;
        } finally {
            fclose($file);
        }
    }
}
