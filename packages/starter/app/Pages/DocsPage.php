<?php

declare(strict_types=1);

namespace App\Pages;

use App\Documentation\Document;
use App\Documentation\DocumentEntry;
use App\Documentation\DocumentIndex;
use App\Documentation\Documents;
use Atom\Http\Response;
use Atom\Page\PageRoute;
use Atom\View\Html;
use Atom\View\Render\HtmlString;

#[PageRoute("/docs", name: "docs.index", title: "Documentation")]
#[PageRoute("/docs/{path*}", name: "docs.show", title: "Documentation")]
final class DocsPage extends AppPage
{
    public string $description = "Documentation built from Markdown files.";
    public ?Document $document = null;
    /** @var DocumentEntry[] */
    public array $navigation = [];
    public ?DocumentEntry $previous = null;
    public ?DocumentEntry $next = null;

    public function get(Documents $documents, DocumentIndex $index, string $path = ""): ?Response
    {
        $this->document = $documents->find($path);
        if ($this->document === null) {
            return (new Response())->status(404)->content("Documentation page not found.");
        }

        $this->title = "{$this->document->title} - Atom Starter";
        $this->description = $this->document->description !== ""
            ? $this->document->description
            : $this->description;
        $this->navigation = $index->all();
        $readingOrder = $this->readingOrder();
        $this->previous = null;
        $this->next = null;
        $position = array_search($this->document->slug, array_column($readingOrder, "slug"), true);
        if (is_int($position)) {
            $this->previous = $readingOrder[$position - 1] ?? null;
            $this->next = $readingOrder[$position + 1] ?? null;
        }

        return null;
    }

    public function content(): HtmlString
    {
        return new HtmlString($this->document === null ? "" : $this->document->html);
    }

    public function navigation(): HtmlString
    {
        return new HtmlString($this->renderNavigationLevel(null));
    }

    /** @return DocumentEntry[] */
    private function readingOrder(?string $parent = null): array
    {
        $entries = [];
        foreach ($this->childrenOf($parent) as $item) {
            $entries[] = $item;
            if ($item->slug !== "") {
                array_push($entries, ...$this->readingOrder($item->slug));
            }
        }

        return $entries;
    }

    private function renderNavigationLevel(?string $parent): string
    {
        $items = $this->childrenOf($parent);
        if ($items === []) {
            return "";
        }

        $html = '<ul class="docs-tree">';
        foreach ($items as $item) {
            $isCurrent = $item->slug === $this->document?->slug;
            $current = $isCurrent ? " is-current" : "";
            $ariaCurrent = $isCurrent ? ' aria-current="page"' : "";
            $link = '<a href="' . Html::escape($item->url()) . '" class="docs-nav-link'
                . $current . '"' . $ariaCurrent . ' atom:navigate>' . Html::escape($item->title) . "</a>";
            $children = $this->childrenOf($item->slug);

            if ($children === []) {
                $html .= "<li>{$link}</li>";
                continue;
            }

            $open = $this->inCurrentBranch($item->slug) ? " open" : "";
            $html .= '<li class="docs-tree-branch">' . $link . '<details' . $open . "><summary>"
                . '<span class="docs-visually-hidden">Toggle ' . Html::escape($item->title)
                . ' section</span></summary>' . $this->renderNavigationLevel($item->slug) . "</details></li>";
        }

        return $html . "</ul>";
    }

    /** @return DocumentEntry[] */
    private function childrenOf(?string $parent): array
    {
        return array_values(array_filter(
            $this->navigation,
            fn(DocumentEntry $entry): bool => $this->parentSlug($entry->slug) === $parent
        ));
    }

    private function inCurrentBranch(string $slug): bool
    {
        $current = $this->document === null ? "" : $this->document->slug;
        return $current === $slug || str_starts_with($current, "{$slug}/");
    }

    private function parentSlug(string $slug): ?string
    {
        if ($slug === "" || !str_contains($slug, "/")) {
            return null;
        }

        return substr($slug, 0, (int) strrpos($slug, "/"));
    }
}
