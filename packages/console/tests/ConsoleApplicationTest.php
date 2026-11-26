<?php

declare(strict_types=1);

namespace Atom\Tests\Console;

use Atom\Console\BufferedConsoleOutput;
use Atom\Console\Command;
use Atom\Console\CommandInterface;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleInput;
use Atom\Console\ConsoleOutput;
use Atom\Console\Attributes\ConsoleCommand;
use Atom\Tests\Console\Fixtures\Commands\DiscoveredHelloCommand;
use PHPUnit\Framework\TestCase;

final class ConsoleApplicationTest extends TestCase
{
    public function testParsesCommandArgumentsAndOptions(): void
    {
        $input = ConsoleInput::fromArgv([
            "atom",
            "make:user",
            "Edin",
            "--role=admin",
            "--force",
            "-v",
        ]);

        $this->assertSame("make:user", $input->commandName());
        $this->assertSame("Edin", $input->argument(0));
        $this->assertSame("admin", $input->option("role"));
        $this->assertTrue($input->option("force"));
        $this->assertTrue($input->option("v"));
    }

    public function testDoubleDashEndsOptionParsing(): void
    {
        $input = ConsoleInput::fromArgv(["atom", "sample", "-v", "--", "--help", "-x", "--"]);

        self::assertSame(["--help", "-x", "--"], $input->arguments());
        self::assertSame(["v" => true], $input->options());
    }

    public function testNegativeNumbersArePositionalArguments(): void
    {
        $input = ConsoleInput::fromArgv(["atom", "sample", "-5", "-0.5", "-1e3", "-v", "--limit=-2"]);

        self::assertSame(["-5", "-0.5", "-1e3"], $input->arguments());
        self::assertSame(["v" => true, "limit" => "-2"], $input->options());
    }

    public function testRunsCommandResolvedByCallback(): void
    {
        $console = $this->consoleWithGreeting("Hello");
        $console->command("greet", ConsoleGreetingCommand::class, "Greets a user");

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "greet", "Atom", "--loud"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("HELLO, ATOM!" . PHP_EOL, $output->output());
    }

    public function testScalarInputAndOutputNamesBindFromArgumentsAndOptions(): void
    {
        $console = (new ConsoleApplication())->attach(ConsoleIoCommands::class);
        $output = new BufferedConsoleOutput();

        self::assertSame(0, $console->run(["atom", "io:scalar", "source", "--output=destination"], $output), $output->errors());
        self::assertSame("source -> destination", $output->output());
    }

    public function testTypedConsoleIoTakesPrecedenceOverParameterNames(): void
    {
        $console = (new ConsoleApplication())->attach(ConsoleIoCommands::class);
        $output = new BufferedConsoleOutput();

        self::assertSame(0, $console->run(["atom", "io:typed", "message"], $output), $output->errors());
        self::assertSame("message" . PHP_EOL, $output->output());
    }

    public function testOmittedBooleanFlagsUseDeclaredDefaultsOrFalse(): void
    {
        $console = (new ConsoleApplication())->attach(ConsoleFlagCommands::class);
        $output = new BufferedConsoleOutput();

        self::assertSame(0, $console->run(["atom", "flags"], $output), $output->errors());
        self::assertSame("[false,true,false,null]", $output->output());
    }

    public function testExplicitBooleanFlagsOverrideDefaults(): void
    {
        $console = (new ConsoleApplication())->attach(ConsoleFlagCommands::class);
        $output = new BufferedConsoleOutput();

        self::assertSame(0, $console->run([
            "atom", "flags", "--verbose", "--enabled=false", "--force", "--confirm=false",
        ], $output), $output->errors());
        self::assertSame("[true,false,true,false]", $output->output());
    }

    public function testCanRunCommandInstance(): void
    {
        $console = new ConsoleApplication();
        $console->command("ping", new ConsolePingCommand());

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "ping"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("pong" . PHP_EOL, $output->output());
    }

    public function testThrowsForDuplicateCommandRegistration(): void
    {
        $console = new ConsoleApplication();
        $console->command("ping", new ConsolePingCommand());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Console command 'ping' is already registered.");

        $console->command("ping", new ConsolePingCommand());
    }

    public function testCanRunBaseCommandClass(): void
    {
        $console = $this->consoleWithGreeting("Made");
        $console->add(ConsoleMakeCommand::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "make:thing", "Article", "--force"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("MADE, ARTICLE! with force" . PHP_EOL, $output->output());
    }

    public function testBaseCommandCanInjectOutputInConstructor(): void
    {
        $console = $this->consoleWithGreeting("Hi");
        $console->add(ConsoleConstructorOutputCommand::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "output:test"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("constructor output" . PHP_EOL, $output->output());
    }

    public function testCanAttachCommandMethodsAndBindParameters(): void
    {
        $console = $this->consoleWithGreeting("Hello");
        $console->attach(ConsoleUserCommands::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "user:greet", "Edin", "--times=2", "--loud"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("HELLO, EDIN!" . PHP_EOL . "HELLO, EDIN!" . PHP_EOL, $output->output());
    }

    public function testAttachedMethodCommandsCanUseTypedServices(): void
    {
        $console = $this->consoleWithGreeting("Hi");
        $console->attach(ConsoleUserCommands::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "user:service", "Atom"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("Hi, Atom!" . PHP_EOL, $output->output());
    }

    public function testAttachedCommandGroupCanInjectOutputInConstructor(): void
    {
        $console = $this->consoleWithGreeting("Hi");
        $console->attach(ConsoleOutputUserCommands::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "user:count"], $output);

        $this->assertSame(0, $code);
        $this->assertSame("42" . PHP_EOL, $output->output());
    }

    public function testRendersHelp(): void
    {
        $console = new ConsoleApplication();
        $console->command("ping", new ConsolePingCommand(), "Checks console wiring");

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "help"], $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Available commands:", $output->output());
        $this->assertStringContainsString("ping  Checks console wiring", $output->output());
    }

    public function testRendersHelpWhenNoCommandIsProvided(): void
    {
        $console = new ConsoleApplication();
        $console->command("ping", new ConsolePingCommand(), "Checks console wiring");

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom"], $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Available commands:", $output->output());
    }

    public function testUnknownCommandReturnsFailure(): void
    {
        $console = new ConsoleApplication();
        $output = new BufferedConsoleOutput();

        $code = $console->run(["atom", "missing"], $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Command 'missing' was not found.", $output->errors());
    }

    public function testCommandExceptionReturnsFailure(): void
    {
        $console = new ConsoleApplication();
        $console->command("fail", new ConsoleFailingCommand());

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "fail"], $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Command 'fail' failed: Boom.", $output->errors());
    }

    public function testMissingRequiredParameterReturnsFailure(): void
    {
        $console = new ConsoleApplication();
        $console->add(ConsoleRequiredArgumentCommand::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "required:test"], $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Command 'required:test' failed: Unable to bind console parameter 'name'.", $output->errors());
    }

    public function testDiscoversCommandClassesAndCommandGroups(): void
    {
        $console = new ConsoleApplication();
        $console->discover(__DIR__ . "/Fixtures/Commands", "Atom\\Tests\\Console\\Fixtures\\Commands");

        $output = new BufferedConsoleOutput();
        $helloCode = $console->run(["atom", "discovered:hello", "Atom"], $output);

        $this->assertSame(0, $helloCode);
        $this->assertSame("Hello, Atom!" . PHP_EOL, $output->output());

        $output = new BufferedConsoleOutput();
        $userCode = $console->run(["atom", "discovered:user", "Edin"], $output);

        $this->assertSame(0, $userCode);
        $this->assertSame("User Edin" . PHP_EOL, $output->output());
        $this->assertTrue($console->commands()->has(DiscoveredHelloCommand::name()));
        $this->assertFalse($console->commands()->has("not:a-command"));
    }
    private function consoleWithGreeting(string $prefix): ConsoleApplication
    {
        return new ConsoleApplication(
            static function (ConsoleInput $input, ConsoleOutput $output) use ($prefix): \Closure {
                $greeting = new ConsoleGreetingService($prefix);

                return static fn(string $class): object => match ($class) {
                    ConsoleGreetingService::class => $greeting,
                    ConsoleGreetingCommand::class => new ConsoleGreetingCommand($greeting),
                    ConsoleUserCommands::class => new ConsoleUserCommands($greeting),
                    ConsoleOutputUserCommands::class => new ConsoleOutputUserCommands($output, $greeting),
                    ConsoleConstructorOutputCommand::class => new ConsoleConstructorOutputCommand($output),
                    default => new $class(),
                };
            }
        );
    }

}

final class ConsoleFlagCommands
{
    #[ConsoleCommand("flags")]
    public function flags(bool $verbose, bool $enabled = true, bool $force = false, ?bool $confirm = null): string
    {
        return json_encode([$verbose, $enabled, $force, $confirm], JSON_THROW_ON_ERROR);
    }
}

final class ConsoleIoCommands
{
    #[ConsoleCommand("io:scalar")]
    public function scalar(string $input, string $output): string
    {
        return "{$input} -> {$output}";
    }

    #[ConsoleCommand("io:typed")]
    public function typed(ConsoleInput $output, ConsoleOutput $input): void
    {
        $input->line($output->argument(0) ?? "");
    }
}

final readonly class ConsoleGreetingService
{
    public function __construct(private string $prefix)
    {
    }

    public function greet(string $name): string
    {
        return "{$this->prefix}, {$name}!";
    }
}

final readonly class ConsoleGreetingCommand implements CommandInterface
{
    public function __construct(private ConsoleGreetingService $greeting)
    {
    }

    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        $message = $this->greeting->greet($input->argument(0, "World") ?? "World");

        if ($input->hasOption("loud")) {
            $message = strtoupper($message);
        }

        $output->line($message);
        return 0;
    }
}

final class ConsolePingCommand implements CommandInterface
{
    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        $output->line("pong");
        return 0;
    }
}

final class ConsoleFailingCommand implements CommandInterface
{
    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        throw new \RuntimeException("Boom.");
    }
}

final class ConsoleMakeCommand extends Command
{
    protected static string $name = "make:thing";
    protected static string $description = "Makes a thing";

    protected function execute(string $name, bool $force, ConsoleGreetingService $greeting): int
    {
        $suffix = $force ? " with force" : "";
        $this->line(strtoupper($greeting->greet($name)) . $suffix);

        return 0;
    }
}

final class ConsoleRequiredArgumentCommand extends Command
{
    protected static string $name = "required:test";

    protected function execute(string $name): int
    {
        $this->line($name);

        return 0;
    }
}

final class ConsoleConstructorOutputCommand extends Command
{
    protected static string $name = "output:test";

    public function __construct(private ConsoleOutput $console)
    {
    }

    protected function execute(): int
    {
        $this->console->line("constructor output");
        return 0;
    }
}

final readonly class ConsoleUserCommands
{
    public function __construct(private ConsoleGreetingService $greeting)
    {
    }

    #[ConsoleCommand("user:greet", "Greets a user")]
    public function greet(string $name, int $times = 1, bool $loud = false): string
    {
        $message = $this->greeting->greet($name);

        if ($loud) {
            $message = strtoupper($message);
        }

        return str_repeat($message . PHP_EOL, $times);
    }

    #[ConsoleCommand("user:service", "Greets a user through explicit service parameter")]
    public function service(string $name, ConsoleGreetingService $greeting, ConsoleOutput $output): int
    {
        $output->line($greeting->greet($name));
        return 0;
    }
}

final readonly class ConsoleOutputUserCommands
{
    public function __construct(
        private ConsoleOutput $output,
        private ConsoleGreetingService $greeting
    ) {
    }

    #[ConsoleCommand("user:count", "Counts users")]
    public function count(): int
    {
        $this->greeting->greet("Atom");
        $this->output->line("42");

        return 0;
    }
}
