<?php

declare(strict_types=1);

namespace Atom\Database\Orm;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;

/**
 * @template TItem
 * @implements IteratorAggregate<int, TItem>
 */
final readonly class PageResult implements Countable, IteratorAggregate, JsonSerializable
{
    /** @param list<TItem> $items */
    public function __construct(
        private array $items,
        private int $totalCount,
        private int $page,
        private int $pageSize
    ) {
        if ($pageSize <= 0) {
            throw new InvalidArgumentException("Page size must be greater than zero.");
        }
    }

    /** @internal */
    public static function clampPage(int $page, int $totalCount, int $pageSize): int
    {
        return max(1, min($page, self::pageCount($totalCount, $pageSize)));
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function totalCount(): int
    {
        return $this->totalCount;
    }

    public function totalPages(): int
    {
        return self::pageCount($this->totalCount, $this->pageSize);
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return TItem|null */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /** @return list<TItem> */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * @template TMapped
     * @param callable(TItem): TMapped $mapper
     * @return self<TMapped>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->totalCount, $this->page, $this->pageSize);
    }

    /** @return ArrayIterator<int, TItem> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    /** @return array{items: list<TItem>, page: int, page_size: int, total: int, total_pages: int} */
    public function jsonSerialize(): array
    {
        return [
            "items" => $this->items,
            "page" => $this->page,
            "page_size" => $this->pageSize,
            "total" => $this->totalCount,
            "total_pages" => $this->totalPages(),
        ];
    }

    private static function pageCount(int $totalCount, int $pageSize): int
    {
        return $totalCount <= 0 ? 1 : 1 + intdiv($totalCount - 1, $pageSize);
    }
}
