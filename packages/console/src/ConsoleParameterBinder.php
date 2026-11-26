<?php

declare(strict_types=1);

namespace Atom\Console;

use Closure;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

final class ConsoleParameterBinder
{
    private int $argumentIndex = 0;

    /** @param (Closure(class-string): object)|null $resolver */
    public function __construct(
        private readonly ConsoleInput $input,
        private readonly ConsoleOutput $output,
        private readonly ?Closure $resolver = null
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function bindPositional(ReflectionFunctionAbstract $method): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $parameters[$parameter->getPosition()] = $this->bind($parameter);
        }

        return $parameters;
    }

    private function bind(ReflectionParameter $parameter): mixed
    {
        $name = $parameter->getName();

        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($typeName === ConsoleInput::class || ($type === null && $name === "input")) {
            return $this->input;
        }

        if ($typeName === ConsoleOutput::class || ($type === null && $name === "output")) {
            return $this->output;
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            if ($this->resolver !== null) {
                return ($this->resolver)($type->getName());
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            throw new RuntimeException("No service resolver configured for console parameter '{$name}'.");
        }

        if ($this->input->hasOption($name)) {
            return $this->coerce($this->input->option($name), $parameter);
        }

        $argument = $this->input->argument($this->argumentIndex);
        if ($argument !== null) {
            $this->argumentIndex++;
            return $this->coerce($argument, $parameter);
        }

        if ($this->isBool($parameter)) {
            return false;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new RuntimeException("Unable to bind console parameter '{$name}'.");
    }

    private function isBool(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === "bool";
    }

    private function coerce(mixed $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            "int" => (int) $value,
            "float" => (float) $value,
            "bool" => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            "string" => is_bool($value) ? ($value ? "1" : "0") : (string) $value,
            default => $value,
        };
    }
}

