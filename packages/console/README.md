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

## Supported API

Console is in maintenance mode. Changes focus on fixes, compatibility, and
documentation for the APIs below.

| Area | API |
| --- | --- |
| Command authoring | Extend `Command`, implement `CommandInterface`, or mark public methods with `#[ConsoleCommand]`. |
| Registration and execution | Use `ConsoleApplication` to register commands and groups, discover command sources, run argv, or handle a prepared `ConsoleInput`. Inspect registered commands through `commands()` and the `CommandRegistry` lookup methods. |
| Input and output | Read positional arguments and options with `ConsoleInput`. Write to separate output and error streams with `ConsoleOutput`, or capture both with `BufferedConsoleOutput`. |
| Binding | Scalar parameters bind from matching options, then positional arguments. Typed `ConsoleInput` and `ConsoleOutput` parameters receive the current invocation's I/O. Omitted boolean flags use their declared default or `false` when no default is declared. |
| Integration | Supply a resolver factory for command and service resolution. Use `ConsoleCommandProviderInterface` and `ConsoleCommandSources` to contribute command directories. Sources can be iterated and observed with `onAdded()`. |

The reflection binder, directory scanner, and method adapter are implementation
details. Base-class and attributed commands can return an integer exit code,
return a string to write to standard output, or return nothing for success.

## Framework integration

Framework's `ConsoleServices` registers built-in and provider commands with
`discoverFrom()` and listens for directories added by modules after the
console has initialized. Its resolver scopes the input and output to each
invocation and resolves command dependencies through Atom DI. Scheduler runs
commands through `ConsoleApplication::run()` and uses `BufferedConsoleOutput`
and the exit code to report the result.

Malformed integer, float, and boolean values produce an error naming the
parameter and exit with code `1` before the command body runs. Integers must
fit PHP's integer range, and floats must be finite. Boolean options accept
bare flags and explicit values such as `true`, `false`, `1`, `0`, `yes`,
`no`, `on`, and `off`.
