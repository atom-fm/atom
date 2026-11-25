<?php

declare(strict_types=1);

namespace Atom\Database\Sql;

final class WhereGroup
{
    /** @var array<int, Condition|CriteriaExpression|ExistsCondition|InSubqueryCondition|self> */
    private array $items = [];

    /** @param ?\Closure(string, ?callable): SelectQuery $relationQuery */
    public function __construct(public readonly string $boolean = "AND", private readonly ?\Closure $relationQuery = null)
    {
    }

    public function where(string $column, mixed $value): self
    {
        $this->items[] = Condition::and($column, $value);
        return $this;
    }

    public function __clone()
    {
        foreach ($this->items as $index => $item) {
            if ($item instanceof self || $item instanceof ExistsCondition || $item instanceof InSubqueryCondition) {
                $this->items[$index] = clone $item;
            }
        }
    }

    public function orWhere(string $column, mixed $value): self
    {
        $this->items[] = Condition::or($column, $value);
        return $this;
    }

    public function whereInSubquery(string $column, SelectQuery $query): self
    {
        $this->items[] = new InSubqueryCondition("AND", Column::from($column), $query);
        return $this;
    }

    /** Parenthesize existing predicates before constraining their combined result. */
    public function groupExisting(): self
    {
        if ($this->items !== []) {
            $group = new self("AND", $this->relationQuery);
            $group->items = $this->items;
            $this->items = [$group];
        }
        return $this;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function whereExp(string $expression, array $parameters = []): self
    {
        $this->items[] = CriteriaExpression::and($expression, $parameters);
        return $this;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function orWhereExp(string $expression, array $parameters = []): self
    {
        $this->items[] = CriteriaExpression::or($expression, $parameters);
        return $this;
    }

    /** @param (callable(self): mixed)|null $constraint */
    public function whereHas(string $relation, ?callable $constraint = null): self
    {
        return $this->addRelationExists("AND", $relation, $constraint);
    }

    /** @param (callable(self): mixed)|null $constraint */
    public function orWhereHas(string $relation, ?callable $constraint = null): self
    {
        return $this->addRelationExists("OR", $relation, $constraint);
    }

    private function addRelationExists(string $boolean, string $relation, ?callable $constraint): self
    {
        if ($this->relationQuery === null) {
            throw new \InvalidArgumentException("Relationship filters require a mapped source.");
        }
        $this->items[] = new ExistsCondition($boolean, ($this->relationQuery)($relation, $constraint));
        return $this;
    }

    /**
     * @param callable(self): mixed $builder
     * @param ?\Closure(string, ?callable): SelectQuery $relationQuery
     */
    public function group(callable $builder, ?\Closure $relationQuery = null): self
    {
        $group = new self("AND", $relationQuery ?? $this->relationQuery);
        $builder($group);
        $this->items[] = $group;
        return $this;
    }

    /** @param callable(self): mixed $builder */
    public function orGroup(callable $builder): self
    {
        $group = new self("OR", $this->relationQuery);
        $builder($group);
        $this->items[] = $group;
        return $this;
    }

    /**
     * @return array<int, Condition|CriteriaExpression|ExistsCondition|InSubqueryCondition|self>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
