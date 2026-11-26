<?php

declare(strict_types=1);

namespace Atom\Console;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\Provider;
use Closure;

/** @internal */
final readonly class ConsoleInjector
{
    public function __construct(private Injector $injector)
    {
    }

    /** @return Closure(class-string): object */
    public function __invoke(ConsoleInput $input, ConsoleOutput $output): Closure
    {
        $injector = $this->injector->createChild([
            Provider::value(ConsoleInput::class, $input),
            Provider::value(ConsoleOutput::class, $output),
        ]);
        $context = new InjectionContext();
        $context->set(ConsoleInput::class, $input);
        $context->set(ConsoleOutput::class, $output);

        return static fn(string $type): object => $injector->get($type, $context);
    }
}
