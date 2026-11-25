<?php

declare(strict_types=1);

namespace Atom\Benchmarks;

use Atom\Application;
use Atom\Benchmarks\Fixtures\Pages\CataloguePage;
use Atom\Http\Request;
use Atom\Page\PageRegistry;
use Atom\View\Ast\TemplateNode;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;
use PhpBench\Attributes as Bench;
use RuntimeException;

#[Bench\BeforeMethods("setUp")]
#[Bench\Revs(100)]
#[Bench\Iterations(5)]
final class CatalogueBench
{
    private string $source;
    private ViewParser $parser;
    private TemplateNode $template;
    private ViewRenderer $renderer;
    private CataloguePage $page;
    private Application $app;

    public function setUp(): void
    {
        $path = __DIR__ . "/Fixtures/Pages/CataloguePage.atom.html";
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Cannot read benchmark template {$path}.");
        }

        $this->source = $source;
        $this->parser = new ViewParser();
        $this->template = $this->parser->parse($source);
        $this->renderer = new ViewRenderer();

        $this->app = new class extends Application {
            protected function pages(PageRegistry $pages): void
            {
                $pages->directory(__DIR__ . "/Fixtures/Pages");
            }
        };

        // Initialize the application and warm its template cache before measuring requests.
        $response = $this->app->handle(new Request("GET", "/catalogue"));
        if ($response->getStatus() !== 200 || !str_contains($response->getContent(), "Product 12")) {
            throw new RuntimeException("The catalogue benchmark did not render its fixture.");
        }

        $this->page = new CataloguePage();
        $this->page->get();
    }

    public function benchParseTemplate(): void
    {
        $this->parser->parse($this->source);
    }

    public function benchRenderParsedTemplate(): void
    {
        $this->renderer->render($this->template, ["this" => $this->page]);
    }

    public function benchHandlePageRequest(): void
    {
        $this->app->handle(new Request("GET", "/catalogue"));
    }
}
