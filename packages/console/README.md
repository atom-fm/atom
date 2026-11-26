# Atom Console

Register, discover, and run command classes or methods marked with
`#[ConsoleCommand]`. Console runs with PHP alone.

For local development, add this directory as a Composer path repository.
Set its package version to `0.1.x-dev` and require
`atom-fm/console:0.1.x-dev` in the consumer project.

```php
<?php

require __DIR__ . "/vendor/autoload.php";

use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleApplication;

final class GreetCommands
{
    #[ConsoleCommand("greet", "Greet someone by name")]
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}

$console = new ConsoleApplication();
$console->attach(GreetCommands::class);
exit($console->run($argv));
```

Save the example as `cli.php` and run `php cli.php greet Atom`.

Use `--` after the command name to pass all following tokens as positional
arguments, even if they start with a dash. Negative numbers such as `-5`
are positional arguments with or without the separator.

By default, Console constructs registered classes without constructor arguments.
You can register an already constructed command or pass a resolver factory to
`ConsoleApplication` for service injection. The factory receives the current
`ConsoleInput` and `ConsoleOutput` and returns a callable that resolves class
names for that invocation. Framework supplies a resolver backed by Atom DI.
