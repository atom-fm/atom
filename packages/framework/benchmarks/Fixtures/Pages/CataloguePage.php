<?php

declare(strict_types=1);

namespace Atom\Benchmarks\Fixtures\Pages;

use Atom\Page\Page;
use Atom\Page\PageRoute;

#[PageRoute("/catalogue")]
final class CataloguePage extends Page
{
    public string $title = "Catalogue";

    /** @var list<array{id: int, name: string, inStock: bool}> */
    public array $products = [];

    public function get(): void
    {
        for ($id = 1; $id <= 12; $id++) {
            $this->products[] = [
                "id" => $id,
                "name" => "Product {$id}",
                "inStock" => $id % 3 !== 0,
            ];
        }
    }
}
