<?php

declare(strict_types=1);

namespace App\Commands;

use App\Documentation\DocumentIndex;
use App\Documentation\Documents;
use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;

final readonly class DocumentationCommands
{
    public function __construct(
        private Documents $documents,
        private DocumentIndex $index,
        private ConsoleOutput $output
    ) {
    }

    #[ConsoleCommand("docs:index", "Generate documentation navigation metadata")]
    public function index(): int
    {
        $entries = $this->documents->entries();
        $this->index->write($entries);
        $count = count($entries);
        $label = $count === 1 ? "page" : "pages";
        $this->output->line("Indexed {$count} documentation {$label}.");

        return 0;
    }
}
