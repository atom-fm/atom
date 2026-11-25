<?php

declare(strict_types=1);

namespace Atom\Collections;

/**
 * @template TValue
 * @extends ReadOnlyCollection<TValue>
 */
class PagedCollection extends ReadOnlyCollection
{
    private int $currentPage = 1;
    private int $pageSize = 0;
    private int $totalCount = 0;

    /**
     * @template TItem
     * @param iterable<array-key, TItem> $items
     * @return static<TItem>
     */
    public static function fromPage(iterable $items, int $totalCount, int $currentPage, int $pageSize): static
    {
        if ($pageSize <= 0) {
            throw new \InvalidArgumentException("Page size must be greater than zero.");
        }

        $collection = new static($items);
        $collection->totalCount = $totalCount;
        $collection->pageSize = $pageSize;
        $collection->currentPage = max(1, $currentPage);

        return $collection;
    }

    public static function clampPage(int $requestedPage, int $totalCount, int $pageSize): int
    {
        if ($pageSize <= 0) {
            throw new \InvalidArgumentException("Page size must be greater than zero.");
        }

        return max(1, min($requestedPage, self::pageCount($totalCount, $pageSize)));
    }

    /**
     * @template TMapped
     * @param callable(TValue): TMapped $mapper
     * @return static<TMapped>
     */
    public function map(callable $mapper): static
    {
        $collection = parent::map($mapper);
        $collection->currentPage = $this->currentPage;
        $collection->pageSize = $this->pageSize;
        $collection->totalCount = $this->totalCount;

        return $collection;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages();
    }

    public function setTotalCount(int $totalCount): void
    {
        $this->totalCount = max(0, $totalCount);
    }

    public function getTotalCount(): int
    {
        return $this->totalCount();
    }

    public function setPageSize(int $pageSize): void
    {
        if ($pageSize <= 0) {
            throw new \InvalidArgumentException("Page size must be greater than zero.");
        }

        $this->pageSize = $pageSize;
    }

    public function getPageSize(): int
    {
        return $this->pageSize();
    }

    public function getTotalPages(): int
    {
        return $this->totalPages();
    }

    public function setTotalPages(int $totalPages): void
    {
        $this->totalCount = max(0, $totalPages) * max(1, $this->pageSize);
    }

    public function getCurrentPage(): int
    {
        return $this->page();
    }

    public function setCurrentPage(int $currentPage): void
    {
        $this->currentPage = max(1, $currentPage);
    }

    public function page(): int
    {
        return $this->currentPage;
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

    public function jsonSerialize(): PagePayload
    {
        return new PagePayload(
            $this->values(),
            $this->page(),
            $this->pageSize(),
            $this->totalCount(),
            $this->totalPages()
        );
    }

    private static function pageCount(int $totalCount, int $pageSize): int
    {
        if ($totalCount <= 0 || $pageSize <= 0) {
            return 1;
        }

        return 1 + intdiv($totalCount - 1, $pageSize);
    }
}
