<?php

declare(strict_types=1);

namespace Atom\View\Component;

final class ComponentRegistry
{
    /** @var array<string, class-string<ComponentInterface>> */
    private array $components = [];
    private bool $sealed = false;

    public function __construct(private readonly ?self $parent = null)
    {
    }

    /**
     * @param class-string<ComponentInterface> $className
     */
    public function register(string $name, string $className): self
    {
        $this->assertMutable();
        $name = trim($name);

        if ($name === "") {
            throw new ComponentRegistryException("Component name cannot be empty.");
        }

        if (isset($this->components[$name])) {
            throw new ComponentRegistryException("Component '{$name}' is already registered.");
        }

        if (!is_a($className, ComponentInterface::class, true)) {
            throw new ComponentRegistryException("Component '{$name}' must implement " . ComponentInterface::class . ".");
        }

        $this->components[$name] = $className;

        return $this;
    }

    public function import(ComponentSet $set): self
    {
        $this->assertMutable();
        foreach ($set as $name => $_className) {
            if (isset($this->components[$name])) {
                throw new ComponentRegistryException("Component '{$name}' is already registered.");
            }
        }

        foreach ($set as $name => $className) {
            $this->components[$name] = $className;
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->components[$name]) || $this->parent?->has($name) === true;
    }

    public function hasOwn(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /**
     * @return class-string<ComponentInterface>
     */
    public function get(string $name): string
    {
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }

        return $this->parent?->get($name)
            ?? throw new ComponentRegistryException("Component '{$name}' is not registered.");
    }

    /**
     * @return array<string, class-string<ComponentInterface>>
     */
    public function all(): array
    {
        return [...($this->parent?->all() ?? []), ...$this->components];
    }

    /** @return array<string, class-string<ComponentInterface>> */
    public function own(): array
    {
        return $this->components;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    public function seal(): self
    {
        $this->sealed = true;

        return $this;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    private function assertMutable(): void
    {
        if ($this->sealed) {
            throw new ComponentRegistryException("Component registry is sealed and cannot be modified.");
        }
    }
}
