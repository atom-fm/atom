<?php

declare(strict_types=1);

namespace Atom\Modules\Accounts\Commands;

use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;
use Atom\Modules\Accounts\AccountManagerInterface;
use Atom\Modules\Accounts\AccountsPublishBundle;
use Atom\Modules\Accounts\RegisterAccount;
use Atom\Publish\Publisher;
use SensitiveParameter;

final readonly class AccountsCommands
{
    public function __construct(
        private AccountsPublishBundle $bundle,
        private Publisher $publisher,
        private AccountManagerInterface $accounts,
        private ConsoleOutput $output
    ) {
    }

    #[ConsoleCommand("accounts:publish", "Publish customizable account models, services, and migrations")]
    public function publish(bool $force = false): int
    {
        $result = $this->publisher->publish($this->bundle->bundle(), $force);

        foreach ($result->published as $file) {
            $this->output->line("Published: " . $this->output->command($file));
        }
        foreach ($result->overwritten as $file) {
            $this->output->line("Overwritten: " . $this->output->command($file));
        }
        foreach ($result->skipped as $file) {
            $this->output->line("Skipped existing: " . $this->output->muted($file));
        }

        return 0;
    }

    #[ConsoleCommand("accounts:user:create", "Create an application user")]
    public function createUser(
        string $login,
        #[SensitiveParameter] string $password,
        string $name = ""
    ): int {
        $identity = $this->accounts->register(new RegisterAccount(
            $login,
            $password,
            ["name" => $name]
        ));

        if ($identity === null) {
            $this->output->errorLine("Unable to create user '{$login}'. The login may already exist.");
            return 1;
        }

        $this->output->line("Created user: " . $this->output->command($login));
        return 0;
    }
}
