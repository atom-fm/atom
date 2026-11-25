<?php

declare(strict_types=1);

namespace Atom\Hydrator;

use Atom\Hydrator\Exception\HydrationException;

final readonly class RequestHydrator
{
    public function __construct(
        private HydrationPlanFactory $plans = new HydrationPlanFactory(),
        private ValueCoercer $coercer = new ValueCoercer()
    ) {
    }

    public function hydrate(string $className, HydrationContext $context): object
    {
        $plan = $this->plans->for($className);
        $arguments = [];
        foreach ($plan->constructorParameters as $parameter) {
            $arguments[] = $this->bindParameter($parameter, $context, $className);
        }
        $instance = $plan->createInstance($arguments);

        foreach ($plan->properties as $property) {
            $resolved = $context->resolve($property->source ?? "auto", $property->sourceName);

            if (!$resolved["found"] && $property->hasDefaultValue) {
                continue;
            }

            $property->setValue(
                $instance,
                $this->coerce($resolved["value"], $context, $property, $className)
            );
        }

        return $instance;
    }

    public function bindParameter(
        HydrationTarget $parameter,
        HydrationContext $context,
        string $handler,
        bool $preserveQueryStrings = false,
        bool $strictJsonIntegers = false
    ): mixed {
        $resolved = $context->resolve($parameter->source ?? "auto", $parameter->sourceName);
        if (!$resolved["found"] && $parameter->hasDefaultValue) {
            return $parameter->defaultValue;
        }

        if ($preserveQueryStrings && $parameter->source === "query" && $parameter->typeName === "string"
            && $parameter->transformers === [] && is_string($resolved["value"])) {
            return $resolved["value"];
        }

        if ($strictJsonIntegers && $parameter->source === "body" && $parameter->typeName === "int"
            && $parameter->transformers === [] && $resolved["value"] !== null && !is_int($resolved["value"])) {
            throw new HydrationException("Body field {$parameter->sourceName} must be an integer.");
        }

        return $this->coerce($resolved["value"], $context, $parameter, $handler);
    }

    private function coerce(mixed $value, HydrationContext $context, HydrationTarget $target, string $className): mixed
    {
        try {
            return $this->coercer->coerce(
                $value,
                $target,
                $className,
                fn(string $nestedClass, array $data): object => $this->hydrate(
                    $nestedClass,
                    new HydrationContext(body: $data)
                )
            );
        } catch (HydrationException $exception) {
            if ($exception->fieldPath === null) {
                throw $exception;
            }

            $path = $exception->source === null
                ? $exception->fieldPath
                : "{$target->sourceName}.{$exception->fieldPath}";
            $source = $exception->source !== null && $exception->source !== "body"
                ? $exception->source
                : $context->sourceFor($target->source ?? "auto", $target->sourceName);

            throw new HydrationException(
                $exception->getMessage(),
                previous: $exception,
                fieldPath: $path,
                source: $source
            );
        }
    }
}
