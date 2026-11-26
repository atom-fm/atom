<?php

declare(strict_types=1);

namespace Atom\Console;

use Closure;
use ReflectionMethod;

final readonly class MethodCommand implements CommandInterface
{
    /**
     * @param class-string $className
     */
    public function __construct(
        private string $className,
        private string $methodName
    ) {
    }

    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        return $this->run($input, $output);
    }

    /** @param (Closure(class-string): object)|null $resolver */
    public function run(ConsoleInput $input, ConsoleOutput $output, ?Closure $resolver = null): int
    {
        $className = $this->className;
        $target = $resolver === null ? new $className() : $resolver($className);
        $method = new ReflectionMethod($className, $this->methodName);
        $parameters = (new ConsoleParameterBinder($input, $output, $resolver))->bindPositional($method);
        $result = $method->invokeArgs($target, $parameters);

        if (is_string($result)) {
            $output->write($result);
        }

        return is_int($result) ? $result : 0;
    }
}
