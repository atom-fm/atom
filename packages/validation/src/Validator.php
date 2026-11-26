<?php

declare(strict_types=1);

namespace Atom\Validation;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

final class Validator
{
    /** @var array<class-string, array{schema: Schema, nested: list<string>}> */
    private static array $attributePlans = [];

    private function __construct(private readonly Schema|string|null $source = null)
    {
    }

    /**
     * @param class-string|Schema|null $source
     */
    public static function for(Schema|string|null $source = null): self
    {
        return new self($source);
    }

    public function validate(array|object $target): ValidationResult
    {
        if ($this->source instanceof Schema) {
            return $this->source->validate($target);
        }

        $className = is_string($this->source)
            ? $this->source
            : (is_object($target) ? $target::class : null);

        if ($className === null) {
            throw new \InvalidArgumentException("A class name is required when validating arrays with attributes.");
        }

        return $this->validateAttributes($target, $className);
    }

    /**
     * @param class-string $className
     * @param list<object> $ancestors
     */
    private function validateAttributes(array|object $target, string $className, array $ancestors = []): ValidationResult
    {
        $plan = self::$attributePlans[$className] ??= $this->buildAttributePlan($className);
        $result = $plan["schema"]->validate($target);
        if (is_object($target)) {
            $ancestors[] = $target;
        }

        foreach ($plan["nested"] as $field) {
            $value = is_array($target) ? ($target[$field] ?? null) : ($target->{$field} ?? null);
            if (!is_object($value) || in_array($value, $ancestors, true)) {
                continue;
            }

            foreach ($this->validateAttributes($value, $value::class, $ancestors)->all() as $error) {
                $result->add($error->forField("{$field}.{$error->field}"));
            }
        }

        return $result;
    }

    /**
     * @param class-string $className
     * @return array{schema: Schema, nested: list<string>}
     */
    private function buildAttributePlan(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $schema = new Schema();
        $nested = [];

        foreach ($reflection->getProperties() as $property) {
            $field = $property->getName();
            foreach ($this->rulesFromProperty($property) as $rule) {
                $schema->field($field)->rule($rule);
            }

            if ($property->getAttributes(ValidateNested::class) !== []) {
                $nested[] = $field;
            }
        }

        return ["schema" => $schema, "nested" => $nested];
    }

    /**
     * @return ValidationRuleInterface[]
     */
    private function rulesFromProperty(ReflectionProperty $property): array
    {
        $rules = [];

        foreach ($property->getAttributes(ValidationRuleInterface::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $rule = $attribute->newInstance();
            if ($rule instanceof ValidationRuleInterface) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}

