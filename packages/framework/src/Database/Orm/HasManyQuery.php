<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

use Atom\Database\Sql\Op;
use Atom\Database\Sql\Query;
use Atom\Database\Sql\SelectQuery;
use Atom\Database\Sql\Table;
use Atom\Database\Sql\WhereGroup;
use Closure;
use InvalidArgumentException;

/** A child query correlated to the parent through its mapped HasMany keys. */
final readonly class HasManyQuery
{
    public EntityMetadata $parent;
    public EntityMetadata $related;
    public string $alias;
    public SelectQuery $query;

    /**
     * @param class-string|null $sourceClass
     * @param string|non-empty-list<string> $relation
     * @param (callable(WhereGroup): mixed)|null $constraint
     */
    public function __construct(?string $sourceClass, ?Table $from, string|array $relation, ?callable $constraint = null)
    {
        if ($sourceClass === null || $from === null) {
            throw new InvalidArgumentException("HasMany queries require a mapped source.");
        }
        $metadata = new EntityMetadataFactory();
        $this->parent = $metadata->for($sourceClass);
        $path = is_array($relation) ? $relation : [$relation];
        $parent = $this->parent;
        $outerAlias = $from->alias ?? $from->name;
        $parentAlias = $outerAlias;
        $parentQuery = null;
        foreach ($path as $index => $name) {
            $mapping = $parent->relation($name);
            if ($mapping === null || $mapping->type !== RelationMetadata::HAS_MANY) {
                throw new InvalidArgumentException("Relationship '{$name}' must be a mapped HasMany relation.");
            }
            $related = $metadata->for($mapping->relatedClass);
            $localKey = $parent->column($mapping->localKey);
            $foreignKey = $related->column($mapping->foreignKey);
            if ($localKey === null || $foreignKey === null) {
                throw new InvalidArgumentException("Relationship '{$name}' must use mapped keys.");
            }
            $alias = $index === array_key_last($path) ? "related" : "related_path_{$index}";
            if (strcasecmp($alias, $outerAlias) === 0) {
                $alias .= "_rows";
            }
            $query = Query::select("{$related->tableName} {$alias}");
            $local = "{$parentAlias}.{$localKey->columnName}";
            $foreign = "{$alias}.{$foreignKey->columnName}";
            if ($parentQuery === null) {
                $query->where($foreign, Op::column($local));
            } else {
                $query->getWhere()->whereInSubquery($foreign, $parentQuery->columns($local));
            }
            $parent = $related;
            $parentAlias = $alias;
            $parentQuery = $query;
        }
        $this->related = $related;
        $this->alias = $alias;
        $this->query = $query;
        if ($constraint !== null) {
            $this->query->getWhere()->group($constraint, self::predicateBuilder($this->related->className, $this->query->getFrom()));
        }
    }

    /**
     * @param class-string|null $sourceClass
     * @return Closure(string, ?callable): SelectQuery
     */
    public static function predicateBuilder(?string $sourceClass, ?Table $from): Closure
    {
        return static fn (string $relation, ?callable $constraint): SelectQuery =>
            (new self($sourceClass, $from, $relation, $constraint))->query;
    }
}
