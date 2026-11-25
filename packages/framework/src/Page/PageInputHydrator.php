<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Http\Request;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Hydrator\HydrationContext;
use Atom\Hydrator\HydrationPlanFactory;
use Atom\Hydrator\HydrationTarget;
use Atom\Hydrator\ValueCoercer;
use Atom\Router\MatchedRoute;
use Atom\Validation\ValidationError;
use Atom\Validation\ValidationResult;
use DateTimeInterface;
use ReflectionAttribute;
use TypeError;

final readonly class PageInputHydrator implements PageInputHydratorInterface
{
    public function __construct(
        private HydrationPlanFactory $plans = new HydrationPlanFactory(),
        private ValueCoercer $coercer = new ValueCoercer()
    ) {
    }

    public function hydrate(Page $page, Request $request, MatchedRoute $route): void
    {
        $this->hydrateProperties($page, $request, $route);
        $this->hydrateForms($page, $request, $route);
    }

    public function hydrateProperties(Page $page, Request $request, MatchedRoute $route): void
    {
        $page->useUriRoute($route);
        $className = $page::class;
        $context = HydrationContext::fromRequest($request, $route->getRouteParams());

        foreach ($this->plans->for($className)->properties as $property) {
            if ($this->isFormModel($property)) {
                continue;
            }

            if ($property->source === null || ($request->getMethod() === "GET" && $property->source === "body")) {
                continue;
            }

            $value = $context->get($property->source, $property->sourceName);
            if ($value === null && $property->hasDefaultValue) {
                continue;
            }

            try {
                $property->setValue($page, $this->coercer->coerce($value, $property, $className));
            } catch (HydrationException $exception) {
                throw new PageActionException(
                    "Unable to hydrate page input {$className}::{$property->name}. " . $exception->getMessage(),
                    previous: $exception
                );
            }
        }
    }

    public function hydrateForms(Page $page, Request $request, MatchedRoute $route): void
    {
        $context = HydrationContext::fromRequest($request, $route->getRouteParams());
        foreach ($this->plans->for($page::class)->properties as $property) {
            if ($this->isFormModel($property)) {
                $this->hydrateFormModel($page, $property, $context, $page::class);
            }
        }
    }

    private function isFormModel(HydrationTarget $property): bool
    {
        return $property->reflection->getAttributes(FormModel::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    private function hydrateFormModel(Page $page, HydrationTarget $property, HydrationContext $context, string $pageClass): void
    {
        if ($property->typeName === "array") {
            $model = $property->property?->isInitialized($page) === true
                ? $property->property->getValue($page)
                : [];

            if (!is_array($model)) {
                $model = [];
            }

            foreach ($context->body as $name => $value) {
                if (str_starts_with((string) $name, "_")) {
                    continue;
                }

                if ($model !== [] && !array_key_exists($name, $model)) {
                    continue;
                }

                $model[$name] = $value;
            }

            $property->setValue($page, $model);
            return;
        }

        if ($property->typeName === null || $property->isBuiltin) {
            throw new PageActionException("Form model {$pageClass}::{$property->name} must be an object or array.");
        }

        $modelClass = $property->typeName;
        $model = $property->property?->isInitialized($page) === true
            ? $property->property->getValue($page)
            : null;

        if ($model === null && $property->allowsNull && $property->property?->isInitialized($page) === true) {
            return;
        }

        if (!$model instanceof $modelClass) {
            $model = $this->plans->for($modelClass)->createInstance();
        }

        $errors = ValidationResult::valid();
        $this->hydrateFormObject($model, $context, $errors);
        $property->setValue($page, $model);

        if ($errors->failed()) {
            $page->addFormInputErrors($model, $errors);
        }
    }

    private function hydrateFormObject(object $model, HydrationContext $context, ValidationResult $errors, string $prefix = ""): void
    {
        foreach ($this->plans->for($model::class)->properties as $field) {
            // Form input may only populate public fields, never private service/context/error state.
            $property = $field->property;
            if ($property?->isPublic() !== true) {
                continue;
            }

            $input = $context->resolve($field->source ?? "body", $field->sourceName);
            if (!$input["found"]) {
                continue;
            }

            $path = $prefix . $field->name;
            try {
                $value = $this->coercer->coerce(
                    $input["value"],
                    $field,
                    $model::class,
                    fn(string $type, array $fields): object => $this->hydrateNestedFormObject(
                        $property->isInitialized($model) ? $property->getValue($model) : null,
                        $type,
                        $fields,
                        $errors,
                        $path
                    )
                );
            } catch (HydrationException) {
                $errors->add(new ValidationError($path, "The field has an invalid value.", "invalid_type"));
                continue;
            }

            try {
                $field->setValue($model, $value);
            } catch (TypeError $exception) {
                if ($property->hasHooks()) {
                    throw $exception;
                }

                $errors->add(new ValidationError($path, "The field has an invalid value.", "invalid_type"));
            }
        }
    }

    /** @param array<string, mixed> $fields */
    private function hydrateNestedFormObject(mixed $current, string $type, array $fields, ValidationResult $errors, string $path): object
    {
        if (is_a($type, DateTimeInterface::class, true)) {
            throw new HydrationException("A date cannot be hydrated from nested form fields.");
        }

        if (!$current instanceof $type) {
            $plan = $this->plans->for($type);
            $constructor = $plan->reflection->getConstructor();
            if (!$plan->reflection->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
                throw new HydrationException("The nested form object must be initialized before binding.");
            }
            $current = $plan->createInstance();
        }

        $this->hydrateFormObject($current, new HydrationContext(body: $fields), $errors, $path . ".");

        return $current;
    }
}
