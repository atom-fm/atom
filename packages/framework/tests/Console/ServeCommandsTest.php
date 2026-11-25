<?php

declare(strict_types=1);

namespace Atom\Tests\Console;

use Atom\Console\BufferedConsoleOutput;
use Atom\Console\Commands\ServeCommands;
use Atom\Support\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServeCommandsTest extends TestCase
{
    #[DataProvider("invalidHosts")]
    public function testRejectsAnInvalidHost(string $host): void
    {
        $command = new ServeCommands(new Paths(__DIR__), new BufferedConsoleOutput());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server host must be a valid hostname or IP address.");

        $command->serve($host);
    }

    /** @return array<string, array{string}> */
    public static function invalidHosts(): array
    {
        return [
            "empty" => [" "],
            "URL" => ["https://localhost"],
        ];
    }

    #[DataProvider("invalidPorts")]
    public function testRejectsAnInvalidPort(int $port): void
    {
        $command = new ServeCommands(new Paths(__DIR__), new BufferedConsoleOutput());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server port must be between 1 and 65535.");

        $command->serve(port: $port);
    }

    /** @return array<string, array{int}> */
    public static function invalidPorts(): array
    {
        return [
            "zero" => [0],
            "above maximum" => [65536],
        ];
    }

    public function testReportsAMissingPublicDirectory(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "atom_serve_" . uniqid();
        $paths = (new Paths($root))->alias("public", $root . "/missing-public");
        $command = new ServeCommands($paths, new BufferedConsoleOutput());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The public directory does not exist:");

        $command->serve();
    }

    public function testReportsAMissingRouter(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "atom_serve_" . uniqid();
        $public = $root . DIRECTORY_SEPARATOR . "public";
        mkdir($public, 0777, true);

        try {
            $paths = (new Paths($root))->alias("public", $public);
            $command = new ServeCommands($paths, new BufferedConsoleOutput());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("The development server router does not exist:");

            $command->serve();
        } finally {
            rmdir($public);
            rmdir($root);
        }
    }
}
