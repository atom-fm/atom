<?php

declare(strict_types=1);

namespace Atom\Console\Commands;

use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;
use Atom\Support\Paths;
use Atom\View\Style\StyleComposer;

final readonly class ComponentStyleCommands
{
    public function __construct(private StyleComposer $composer, private Paths $paths, private ConsoleOutput $output)
    {
    }

    #[ConsoleCommand("styles:build", "Build adjacent component styles into one stylesheet")]
    public function build(string $output = "@public/assets/components.css"): int
    {
        $destination = $this->paths->resolve($output);
        $this->composer->build($destination);
        $this->output->line("Component styles written to {$destination}");
        return 0;
    }
}
