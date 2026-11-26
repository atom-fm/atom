# Console

[Atom Framework](Index.md)

Atom console commands are small classes resolved through the DI container.
Framework installs [`atom-fm/console`](../packages/console/README.md) and
registers its built-in and module commands automatically.

## Command Class

### Check a JSON file before publishing it

Add a `json:check` command that a release script can run before publishing a JSON file. It reports unreadable files and syntax errors on standard error. A successful check can run quietly in CI.

Save this class as `app/Commands/CheckJsonCommand.php` in an installed Atom Starter project. The Starter's `DocumentationServiceProvider` already registers `app/Commands` for discovery.

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use Atom\Console\Command;

final class CheckJsonCommand extends Command
{
    protected static string $name = "json:check";
    protected static string $description = "Check the syntax of a JSON file";

    protected function execute(string $path, bool $quiet = false): int
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            $this->errorLine("Cannot read {$path}.");
            return 1;
        }

        if (!json_validate($json)) {
            $this->errorLine("Invalid JSON in {$path}: " . json_last_error_msg());
            return 1;
        }

        if (!$quiet) {
            $this->line("Valid JSON in {$path}.");
        }

        return 0;
    }
}
```

Run these commands from the project root in PowerShell.

```powershell
php atom json:check composer.json
php atom json:check composer.json --quiet
$LASTEXITCODE
```

The first call prints `Valid JSON in composer.json.` The second accepts the same positional path, binds `--quiet` to the boolean parameter, and exits with code `0`.

To check the failure path, create a disposable file containing an unfinished JSON object.

```powershell
$sample = [System.IO.Path]::GetTempFileName()
Set-Content -LiteralPath $sample -Value '{"title":' -Encoding ascii
php atom json:check $sample --quiet
$LASTEXITCODE
Remove-Item -LiteralPath $sample
```

Even with `--quiet`, the invalid file produces an error naming the file and exits with code `1`. A script can use the exit code to stop publication.

The base class provides:

```php
$this->argument(0);
$this->arguments();
$this->option("name");
$this->hasOption("force");
$this->write("Loading...");
$this->line("done");
$this->errorLine("Something failed");
```

`execute()` can also receive bound parameters:

```php
protected function execute(string $name, string $role = "user", bool $force = false): int
{
    // php atom user:create Edin --role=admin --force
}
```

Binding rules are the same as command groups:

- positional arguments bind to scalar parameters in method order
- `--name=value` binds to a parameter with the same name
- `--force` binds to `bool $force`
- class-typed parameters are resolved from DI
- `ConsoleInput` and `ConsoleOutput` can be injected through the constructor or requested directly
- returning `string` writes to output
- returning `int` sets the exit code

For lower-level control, implement `CommandInterface` directly:

```php
use Atom\Console\CommandInterface;
use Atom\Console\ConsoleInput;
use Atom\Console\ConsoleOutput;

final readonly class GreetCommand implements CommandInterface
{
    public function __construct(private GreetingService $greeting)
    {
    }

    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        $name = $input->argument(0, "World");

        $output->line($this->greeting->greet($name));

        return 0;
    }
}
```

## Register Commands

```php
use Atom\Console\ConsoleApplication;

$console = new ConsoleApplication($injector);

$console->add(GreetCommand::class);
```

Command classes are resolved through the injector, so constructor dependencies work normally.

You can also register a class with an explicit name:

```php
$console->command("greet", GreetCommand::class, "Greets a user");
```

Command instances can also be registered:

```php
$console->command("ping", new PingCommand());
```

## Command Groups

Small related commands can be grouped into one class with method attributes:

```php
use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;

final readonly class UserCommands
{
    public function __construct(
        private UserRepository $users,
        private ConsoleOutput $output
    ) {
    }

    #[ConsoleCommand("user:create", "Creates a user")]
    public function create(string $name, string $role = "user", bool $force = false): int
    {
        $this->users->create($name, $role, $force);

        return 0;
    }

    #[ConsoleCommand("user:greet", "Greets a user")]
    public function greet(string $name): string
    {
        return "Hello, {$name}!" . PHP_EOL;
    }

    #[ConsoleCommand("user:count", "Counts users")]
    public function count(): int
    {
        $this->output->line((string) $this->users->count());

        return 0;
    }
}
```

Attach the group:

```php
$console->attach(UserCommands::class);
```

Method parameters are bound from CLI arguments and options:

```text
php atom user:create Edin --role=admin --force
```

Binding rules:

- positional arguments bind to scalar parameters in method order
- `--name=value` binds to a parameter with the same name
- `--force` binds to `bool $force`
- class-typed parameters are resolved from DI
- `ConsoleInput` and `ConsoleOutput` are command-scoped services and can be constructor-injected
- returning `string` writes to output
- returning `int` sets the exit code

## Discovery

Commands can be discovered from a PSR-4 style folder:

```php
$console = new ConsoleApplication($injector);

$console->discover(__DIR__ . "/Commands", "App\\Commands");

exit($console->run($argv));
```

This discovers both command classes:

```php
namespace App\Commands;

use Atom\Console\Command;

final class GreetCommand extends Command
{
    protected static string $name = "greet";
    protected static string $description = "Greets a user";

    protected function execute(string $name = "World"): string
    {
        return "Hello, {$name}!" . PHP_EOL;
    }
}
```

And command groups:

```php
namespace App\Commands;

use Atom\Console\Attributes\ConsoleCommand;

final readonly class UserCommands
{
    #[ConsoleCommand("user:create", "Creates a user")]
    public function create(string $name): int
    {
        return 0;
    }
}
```

Discovered commands appear in `help`:

```text
php atom help
```

## Application Console

Applications can use the framework-provided console application:

```php
$app = new App\Application();
$app->initialize();

exit($app->getConsole()->run($argv));
```

Framework services can also provide command discovery paths. A service provider may implement `ConsoleCommandProviderInterface`:

```php
use Atom\Console\ConsoleCommandProviderInterface;
use Atom\Console\ConsoleCommandSources;
use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;

final class BlogServices implements ServiceProviderInterface, ConsoleCommandProviderInterface
{
    public function register(Bindings $bindings): void
    {
    }

    public function consoleCommands(ConsoleCommandSources $commands): void
    {
        $commands->add(__DIR__ . "/Commands", "App\\Blog\\Commands");
    }
}
```

When the application resolves `ConsoleApplication`, commands from all providers are discovered automatically. Atom also exposes built-in framework commands this way.

Installed modules can contribute command directories directly from their `register()` method:

```php
public function register(ModuleContext $context): void
{
    $context->commands(
        __DIR__ . "/Commands",
        __NAMESPACE__ . "\\Commands"
    );
}
```

This uses the same command discovery mechanism as console command providers and keeps optional module commands tied to module installation.

## Run

```php
exit($console->run($argv));
```

Arguments and options are available through `ConsoleInput`:

```php
$input->commandName();
$input->argument(0);
$input->arguments();
$input->option("name");
$input->hasOption("force");
```

Supported option forms:

```text
atom greet Edin --loud --role=admin -v
```

## Output

Use `ConsoleOutput` for command output:

```php
$output->write("Loading...");
$output->line("done");
$output->errorLine("Something failed");
```

### Test a command's output and exit code

Use the `CheckJsonCommand` from [Check a JSON file before publishing it](#check-a-json-file-before-publishing-it) to test what a release script sees. Starter already has two inputs you can use. Its `composer.json` is valid JSON, and its `atom` launcher contains PHP.

Save this as `tests/json-check.php`. It boots the same console as `php atom` and passes each invocation a fresh `BufferedConsoleOutput` so you can check standard output and standard error separately.

```php
<?php

declare(strict_types=1);

use App\Application;
use Atom\Console\BufferedConsoleOutput;

require dirname(__DIR__) . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$console = $app->getConsole();

$valid = new BufferedConsoleOutput();
$code = $console->run(["atom", "json:check", "composer.json"], $valid);
if ($code !== 0 || $valid->output() !== "Valid JSON in composer.json." . PHP_EOL || $valid->errors() !== "") {
    throw new RuntimeException("Expected a success message on standard output.");
}

$invalid = new BufferedConsoleOutput();
$code = $console->run(["atom", "json:check", "atom", "--quiet"], $invalid);
if ($code !== 1 || $invalid->output() !== "" || !str_starts_with($invalid->errors(), "Invalid JSON in atom:")) {
    throw new RuntimeException("Expected a JSON error on standard error, even with --quiet.");
}

echo "JSON command checks passed.", PHP_EOL;
```

From the Starter project root, run `php tests/json-check.php`. It prints `JSON command checks passed.` and exits with code `0` when both cases pass. A change to the exit code, message, or output stream makes the check fail.

## File Templates

### Generate pages with the starter layout

In Atom Starter, `App\Pages\AppPage` gives a page the site's header, navigation, and footer. You can make `php atom make:page` use that base class for every new page by overriding the page class template once.

From the starter project root, create the directory `templates/atom/page/`. Save the following as `templates/atom/page/page.php.tpl`. Keep the spaces inside the placeholders. The generator fills them in when it writes the PHP class.

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use App\Pages\AppPage;
use Atom\Page\PageRoute;

#[PageRoute("{{ route }}")]
final class {{ class }} extends AppPage
{
    public string $title = "{{ title }}";

    public function get(): void
    {
    }
}
```

Generate a release notes page from the project root.

```shell
php atom make:page "Release Notes" /releases
```

The command creates two files.

- `app/Pages/ReleaseNotesPage.php` extends `AppPage`, has the title `Release Notes`, and maps to `/releases`.
- `app/Pages/ReleaseNotesPage.atom.html` contains the heading `Release Notes`. Atom uses the framework's view template because the project supplied only a class template.

Open `/releases` in the running starter site. You should see the new heading beneath the existing site navigation. Edit the generated view to add the release notes. Later edits to the generator template apply to newly generated pages.

Atom looks for each template in `templates/atom/` first, then in the framework's `src/Templates/` directory. To customize the generated markup as well, add `templates/atom/page/page.atom.html.tpl`. The view template receives `{{ title }}`. The same lookup order applies to `make:component`, `make:migration`, and `make:seeder`.

## Make Commands

Atom ships with a few project generators:

```text
php atom make:page Articles
php atom make:page "Admin Users" /admin/users
php atom make:component AlertBox
```

`make:page` creates:

```text
app/Pages/ArticlesPage.php
app/Pages/ArticlesPage.atom.html
```

By default, the generated page uses `#[PageRoute]` and extends `Atom\Page\Page`.

`make:component` creates:

```text
app/Components/AlertBox.php
```

Generated files are intentionally small. Customize them by copying the matching framework template into `templates/atom` and editing the copy.

## Development Server

Start PHP's built-in server from an Atom application:

```text
php atom serve
php atom serve --host=0.0.0.0 --port=8080
```

The command serves the configured `@public` directory through its `server.php` router and defaults to `127.0.0.1:8020`. It is intended only for local development.

## Help

`help`, `--help`, and `-h` render the registered command list.
