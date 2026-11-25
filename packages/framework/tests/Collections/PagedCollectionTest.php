<?php

declare(strict_types=1);

namespace Atom\Tests\Collections;

use Atom\Collections\PagedCollection;
use PHPUnit\Framework\TestCase;

final class PagedCollectionTest extends TestCase
{
    public function testFromPageBuildsPaginationMetadata(): void
    {
        $collection = PagedCollection::fromPage([1, 2], 5, 2, 2);

        $this->assertSame([1, 2], $collection->toArray());
        $this->assertSame(5, $collection->getTotalCount());
        $this->assertSame(2, $collection->getPageSize());
        $this->assertSame(2, $collection->getCurrentPage());
        $this->assertSame(3, $collection->getTotalPages());
        $this->assertSame(2, $collection->page());
        $this->assertSame(2, $collection->pageSize());
        $this->assertSame(5, $collection->totalCount());
        $this->assertSame(3, $collection->totalPages());
        $this->assertTrue($collection->hasPrevious());
        $this->assertTrue($collection->hasNext());
    }

    public function testFromPageRequiresPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PagedCollection::fromPage([], 0, 1, 0);
    }

    public function testClampPageKeepsRequestsWithinTheResultWindow(): void
    {
        self::assertSame(1, PagedCollection::clampPage(99, 0, 25));
        self::assertSame(1, PagedCollection::clampPage(-1, 51, 25));
        self::assertSame(2, PagedCollection::clampPage(2, 51, 25));
        self::assertSame(2, PagedCollection::clampPage(99, 50, 25));
        self::assertSame(3, PagedCollection::clampPage(99, 51, 25));
        self::assertSame(PHP_INT_MAX, PagedCollection::clampPage(PHP_INT_MAX, PHP_INT_MAX, 1));
    }

    public function testClampPageRequiresPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PagedCollection::clampPage(1, 0, 0);
    }

    public function testMapRetainsPaginationMetadataAndKeys(): void
    {
        $collection = PagedCollection::fromPage(['one' => 1, 'two' => 2], 9, 2, 2);
        $mapped = $collection->map(static fn(int $value): string => 'Row ' . $value);

        self::assertSame(['one' => 'Row 1', 'two' => 'Row 2'], $mapped->toArray());
        self::assertSame(['one' => 1, 'two' => 2], $collection->toArray());
        self::assertSame(2, $mapped->page());
        self::assertSame(2, $mapped->pageSize());
        self::assertSame(9, $mapped->totalCount());
        self::assertSame(5, $mapped->totalPages());
        self::assertSame(
            '{"items":["Row 1","Row 2"],"page":2,"page_size":2,"total":9,"total_pages":5}',
            json_encode($mapped, JSON_THROW_ON_ERROR)
        );
    }
}
