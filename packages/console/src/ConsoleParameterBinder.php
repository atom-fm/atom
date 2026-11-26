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

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($this->isBool($parameter)) {
            return false;
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

        $typeName = $type->getName();
        $converted = match ($typeName) {
            "int" => is_string($value) ? $this->parseInt($value) : null,
            "float" => is_string($value) && is_numeric($value) && is_finite((float) $value) ? (float) $value : null,
            "bool" => is_bool($value) || (is_string($value) && trim($value) !== "")
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            "string" => is_bool($value) ? ($value ? "1" : "0") : (string) $value,
            default => $value,
        };

        if ($converted === null && in_array($typeName, ["int", "float", "bool"], true)) {
            throw new RuntimeException("Invalid value for console parameter '{$parameter->getName()}'. Expected {$typeName}.");
        }

        return $converted;
    }

    private function parseInt(string $value): ?int
    {
        // Preserve leading-zero decimal inputs while checking for integer overflow.
        $decimal = preg_replace('/^([+-]?)0+(?=[0-9])/', '$1', trim($value));
        $integer = filter_var($decimal, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }
}

