# Publishing Files

[Atom Framework](Index.md)

Publishing copies a file supplied by Atom or a module into your application so you can edit it. Try it with the database queue migration and check what happens to a local edit when you publish again.

## Try publishing the queue migration

Start in a fresh Atom Starter project with Composer dependencies installed. Run the publish command from the project root.

```shell
php atom queue:publish
```

Look for `Published:` followed by the path to `app/Database/Migrations/M0001_create_atom_queue_tables.php`. Open that file to see the schema for `atom_jobs` and `atom_failed_jobs`. Publishing creates the migration file. The tables are created when you later run `php atom migrate`.

Add the PHP comment `// Application copy` below the opening `<?php` in the published file. Run `php atom queue:publish` again. This time the command prints `Skipped existing:` and the comment is still in the file.

To deliberately restore the framework version in this demo, run `php atom queue:publish --force`. The command prints `Overwritten:` and the local comment is gone. For the next steps in setting up the database queue, see [Queues and scheduling](Queue.md).

## Define a Bundle

The queue command declares one source file and one destination. In the command class, `__DIR__` is the framework's `Queue/Commands` directory, so the bundle's source directory is `Queue/Resources`.

```php
use Atom\Publish\PublishBundle;

$bundle = (new PublishBundle("queue", dirname(__DIR__) . "/Resources"))
    ->file("M0001_create_atom_queue_tables.php", "@migrations/M0001_create_atom_queue_tables.php");
```

Each call to `file()` adds one mapping. Relative source names use the bundle's source directory, or `@root` if none was supplied. A source or destination can also use an absolute path or a registered alias. The starter maps `@migrations` to `app/Database/Migrations`.

## Publish a Bundle

In a command that receives an `Atom\Publish\Publisher`, pass the bundle to `publish()`. Its optional `force` argument defaults to `false`. To expose a CLI flag, forward the command's boolean `$force` parameter.

```php
$result = $this->publisher->publish($bundle, force: $force);
```

| What happened to a destination | Where its path appears |
| --- | --- |
| A new file was copied | `$result->published` |
| An existing file was kept | `$result->skipped` |
| An existing file was replaced | `$result->overwritten` |

`$result->changed()` returns true if any file was published or overwritten. Before copying, the publisher checks that every source is readable, destinations are distinct, and a destination is not a directory. It creates missing parent directories as it writes.

### Check a two-file bundle before shipping it

Suppose your billing module ships a receipt and a payment reminder that each application can customize. Test the bundle in a fresh Starter app to catch a missing template before distributing the module.

Save `publish-billing.php` beside the `atom` command. It uses the application's `Publisher` to copy both templates into `templates/billing` and reports how many files it created or kept.

```php
<?php

declare(strict_types=1);

use App\Application;
use Atom\Publish\PublishBundle;
use Atom\Publish\PublishException;
use Atom\Publish\Publisher;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$publisher = $app->getInjector()->get(Publisher::class);
$bundle = (new PublishBundle("billing", __DIR__ . "/billing-defaults"))
    ->file("receipt.txt", "@root/templates/billing/receipt.txt")
    ->file("reminder.txt", "@root/templates/billing/reminder.txt");

try {
    $result = $publisher->publish($bundle);
} catch (PublishException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

printf("Published %d, kept %d.\n", count($result->published), count($result->skipped));
```

From the project root, supply only the receipt for the first run.

```powershell
New-Item -ItemType Directory billing-defaults | Out-Null
Set-Content billing-defaults/receipt.txt "Thank you for your payment."
php publish-billing.php
$LASTEXITCODE
Test-Path templates/billing/receipt.txt
```

The error names `reminder.txt`, the exit code is `1`, and `Test-Path` returns `False`. The publisher checks every source before it starts copying, so the readable receipt has not yet been published.

Add the missing reminder and rerun the same script.

```powershell
Set-Content billing-defaults/reminder.txt "Your payment is due."
php publish-billing.php
Get-Content templates/billing/reminder.txt
php publish-billing.php
```

The first successful run prints `Published 2, kept 0.` and the reminder reads `Your payment is due.` The next run prints `Published 0, kept 2.` because both destination files now exist.

## Thin Module Command

An installed module can register its command directory with `ModuleContext::commands()`. The command only defines its bundle, invokes the framework publisher, and formats the result:

```php
final readonly class AccountsCommands
{
    public function __construct(
        private AccountsPublishBundle $bundle,
        private Publisher $publisher,
        private ConsoleOutput $output
    ) {
    }

    #[ConsoleCommand("accounts:publish", "Publish account scaffolding")]
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
            $this->output->line("Skipped: " . $this->output->muted($file));
        }

        return 0;
    }
}
```

Published files are application-owned. The framework does not track or update them after publication.
