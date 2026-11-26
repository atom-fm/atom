<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

use Atom\Database\Db;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\BelongsTo;
use Atom\Database\Orm\Attributes\HasMany;
use Atom\Database\Orm\Attributes\HasOne;
use Atom\Database\Orm\Relation\BelongsToRelation;
use Atom\Database\Orm\Relation\HasManyRelation;
use Atom\Database\Orm\Relation\HasOneRelation;
use Atom\Database\Orm\Relation\Relation;
use LogicException;
use ReflectionProperty;
use RuntimeException;

final readonly class RelationMetadata
{
    public const BELONGS_TO = "belongsTo";
    public const HAS_ONE = "hasOne";
    public const HAS_MANY = "hasMany";

    /**
     * @param class-string $relatedClass
     * @param list<string> $orderBy
     */
    public function __construct(
        public string $type,
        public string $propertyName,
        public string $relatedClass,
        public string $foreignKey,
        public string $ownerKey,
        public string $localKey,
        private ReflectionProperty $property,
        public array $orderBy = [],
    ) {
    }

    public static function belongsTo(ReflectionProperty $property, BelongsTo $relation): self
    {
        return new self(
            type: self::BELONGS_TO,
            propertyName: $property->getName(),
            relatedClass: $relation->relatedClass,
            foreignKey: $relation->foreignKey,
            ownerKey: $relation->ownerKey,
            localKey: $relation->ownerKey,
            property: $property
        );
    }

    public static function hasOne(ReflectionProperty $property, HasOne $relation): self
    {
        return new self(
            type: self::HAS_ONE,
            propertyName: $property->getName(),
            relatedClass: $relation->relatedClass,
            foreignKey: $relation->foreignKey,
            ownerKey: $relation->localKey,
            localKey: $relation->localKey,
            property: $property
        );
    }

    public static function hasMany(ReflectionProperty $property, HasMany $relation): self
    {
        return new self(
            type: self::HAS_MANY,
            propertyName: $property->getName(),
            relatedClass: $relation->relatedClass,
            foreignKey: $relation->foreignKey,
            ownerKey: $relation->localKey,
            localKey: $relation->localKey,
            property: $property,
            orderBy: $relation->orderBy,
        );
    }

    /** @internal */
    public function assertConnection(Db $db, string $path): void
    {
        $related = $this->relatedClass;
        $configured = is_subclass_of($related, Model::class) ? $related::configuredDatabase() : null;
        if ($configured !== null && $configured->connection() !== $db->connection()) {
            throw new RuntimeException("Relationship '{$path}' targets {$related} on a different database connection.");
        }
    }

    public function setValue(object $entity, mixed $value): void
    {
        $this->property->setValue($entity, $value);
    }

    /**
     * @param object[] $parents
     * @return list<object>
     */
    public function relatedModels(array $parents): array
    {
        $models = [];
        foreach ($parents as $parent) {
            $value = $this->property->getValue($parent);
            foreach (is_array($value) ? $value : [$value] as $model) {
                if ($model !== null) {
                    $models[spl_object_id($model)] = $model;
                }
            }
        }
        return array_values($models);
    }

    public function createRelation(EntityMetadataFactory $metadataFactory): Relation
    {
        return match ($this->type) {
            self::BELONGS_TO => new BelongsToRelation($this, $metadataFactory),
            self::HAS_ONE => new HasOneRelation($this, $metadataFactory),
            self::HAS_MANY => new HasManyRelation($this, $metadataFactory),
            default => throw new LogicException("Unsupported relation type '{$this->type}'."),
        };
    }
}
