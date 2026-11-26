<?php

declare(strict_types=1);

namespace Atom\Tests\Console;

use Atom\Console\BufferedConsoleOutput;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleServices;
use Atom\Console\Make\MakeOptions;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Scheduler\ScheduleServices;
use PHPUnit\Framework\TestCase;

final class ConsoleServicesTest extends TestCase
{
    public function testDiscoversCommandSourcesProvidedByServices(): void
    {
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(ScheduleServices::class);
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers);

        $console = Injector::create($bindings)->get(ConsoleApplication::class);

        $this->assertTrue($console->commands()->has("atom:about"));
        $this->assertTrue($console->commands()->has("serve"));
        $this->assertTrue($console->commands()->has("schedule:list"));
    }

    public function testFrameworkMakePageCommandCreatesFiles(): void
    {
        $root = $this->tempDirectory();
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class);
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers)
            ->value(MakeOptions::class, new MakeOptions(root: $root));

        $console = Injector::create($bindings)->get(ConsoleApplication::class);

        $this->assertTrue($console->commands()->has("make:page"));
        $this->assertTrue($console->commands()->has("make:component"));

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "make:page", "Reports"], $output);

        $this->assertSame(0, $code);
        $this->assertFileExists($root . "/app/Pages/ReportsPage.php");
        $this->assertFileExists($root . "/app/Pages/ReportsPage.atom.html");
        $this->assertStringContainsString("Created page: app/Pages/ReportsPage.php", $output->output());
        $this->assertStringContainsString("Created view: app/Pages/ReportsPage.atom.html", $output->output());
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "atom_console_" . uniqid();
        mkdir($directory, 0777, true);

        return $directory;
    }
}
