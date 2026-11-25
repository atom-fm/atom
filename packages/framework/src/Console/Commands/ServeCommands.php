<?php

declare(strict_types=1);

namespace Atom\Console\Commands;

use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;
use Atom\Support\Paths;
use RuntimeException;

final readonly class ServeCommands
{
    public function __construct(
        private Paths $paths,
        private ConsoleOutput $output
    ) {
    }

    #[ConsoleCommand("serve", "Start the local development server")]
    public function serve(string $host = "127.0.0.1", int $port = 8020): int
    {
        $host = trim($host, " \t\n\r\0\x0B[]");
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if ($host === "" || !$validHost) {
            throw new RuntimeException("The server host must be a valid hostname or IP address.");
        }

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException("The server port must be between 1 and 65535.");
        }

        $root = $this->paths->resolve("@root");
        $public = $this->paths->resolve("@public");
        $router = rtrim($public, "/\\") . DIRECTORY_SEPARATOR . "server.php";
        if (!is_dir($public)) {
            throw new RuntimeException("The public directory does not exist: {$public}");
        }

        if (!is_file($router)) {
            throw new RuntimeException("The development server router does not exist: {$router}");
        }

        $address = str_contains($host, ":") ? "[{$host}]:{$port}" : "{$host}:{$port}";
        $this->output->line("Atom development server: http://{$address}");
        $this->output->line("Press Ctrl+C to stop. This server is for local development only.");

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, "-S", $address, "-t", $public, $router],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $root
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Unable to start the PHP development server.");
        }

        return proc_close($process);
    }
}
