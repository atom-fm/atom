<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Http\HttpException;
use Atom\Http\MiddlewareInterface;
use Atom\Http\Response;
use Atom\Router\Route;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteEntry;
use Atom\Validation\ValidationResult;
use Atom\Validation\Validator;
use Atom\View\Component\ComponentContextValueInterface;
use ReflectionNamedType;
use ReflectionType;
use RuntimeException;

abstract class Page implements ComponentContextValueInterface
{
    public ?string $layout = null;
    private ?MatchedRoute $uriRoute = null;
    private ?ValidationResult $validation = null;
    private ?ValidationResult $formInputErrors = null;
    private bool $hasFormInputErrors = false;
    private string $flashMessage = "";
    private string $flashTitle = "";
    private string $flashVariant = "success";

    /** @internal */
    final public function supportsComponentProperty(string $name, ?ReflectionType $type): bool
    {
        return $name === "page"
            && $type instanceof ReflectionNamedType
            && is_a($type->getName(), self::class, true);
    }

    /**
     * @param array<class-string<MiddlewareInterface>|MiddlewareInterface> $middlewares
     * @return RouteEntry[]
     */
    public static function registerPages(?string $directory = null, array $middlewares = []): array
    {
        return (new PageRouteRegistrar())->registerDirectory(
            Route::getRouter(),
            $directory ?? self::defaultPageDirectory(),
            middlewares: $middlewares
        );
    }

    private static function defaultPageDirectory(): string
    {
        $callerFile = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame["file"] ?? null;
            if (is_string($file) && realpath($file) !== realpath(__FILE__)) {
                $callerFile = $file;
                break;
            }
        }

        if ($callerFile === null) {
            throw new RuntimeException("Cannot infer page directory. Pass it explicitly to Page::registerPages().");
        }

        return dirname($callerFile) . DIRECTORY_SEPARATOR . "Pages";
    }

    /** @internal Bound by the Page request lifecycle. */
    public function useUriRoute(MatchedRoute $route): void
    {
        $this->uriRoute = $route;
    }

    /** @param array<string, mixed> $overrides */
    public function url(array $overrides = []): string
    {
        return (new PageUri())->url($this, $this->uriRoute ?? throw new PageActionException("Page URL requires a matched route."), $overrides);
    }

    /** Stop the request and let the configured error handler render a 404. */
    protected function notFound(string $message = ""): never
    {
        throw new HttpException(404, $message);
    }

    /** Prepare models/forms after Page inputs are bound, before form input hydration. */
    public function init(): void
    {
    }

    public function template(): ?string
    {
        return null;
    }

    /** Called after a directly submitted form save succeeds. */
    public function formSaved(Form $form): ?Response
    {
        return null;
    }

    public function validate(): bool
    {
        $this->validation = Validator::for()->validate($this);

        return $this->validation->passed();
    }

    /** @param array<string, mixed>|object $model */
    protected function validateModel(array|object $model, string|null $className = null): bool
    {
        $this->validation = Validator::for($className)->validate($model);

        return $this->validation->passed();
    }

    public function errors(): ValidationResult
    {
        $result = ValidationResult::valid()->merge($this->validation ?? ValidationResult::valid());
        if ($this->formInputErrors !== null) {
            $result->merge($this->formInputErrors);
        }
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(FormModel::class) === [] || !$property->isInitialized($this)) {
                continue;
            }
            $form = $property->getValue($this);
            if ($form instanceof Form) {
                $result->merge($form->errors());
            }
        }
        return $result;
    }

    /** @internal */
    final public function addFormInputErrors(object $model, ValidationResult $errors): void
    {
        $this->hasFormInputErrors = true;
        if ($model instanceof Form) {
            $model->addInputErrors($errors);
        } else {
            ($this->formInputErrors ??= ValidationResult::valid())->merge($errors);
        }
    }

    /** @internal */
    final public function hasFormInputErrors(): bool
    {
        return $this->hasFormInputErrors;
    }

    public function flash(string $message, string $variant = "success", string $title = ""): void
    {
        $this->flashMessage = $message;
        $this->flashVariant = $variant;
        $this->flashTitle = $title;
    }

    #[PageAction("clearFlash")]
    public function clearFlash(): void
    {
        $this->flashMessage = "";
        $this->flashTitle = "";
        $this->flashVariant = "success";
    }

    public function hasFlash(): bool
    {
        return $this->flashMessage !== "" || $this->flashTitle !== "";
    }

    public function flashMessage(): string
    {
        return $this->flashMessage;
    }

    public function flashTitle(): string
    {
        return $this->flashTitle;
    }

    public function flashVariant(): string
    {
        return $this->flashVariant;
    }

    protected function setValidation(ValidationResult $validation): void
    {
        $this->validation = $validation;
    }
}
