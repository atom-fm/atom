<?php

declare(strict_types=1);

namespace Atom\Tests\View;

use Atom\Di\InjectionContext;
use Atom\Di\ServiceProviderRegistry;
use Atom\Tests\View\PageFixtures\ArticleListPage;
use Atom\View\Ast\ElementNode;
use Atom\View\Ast\ExpressionNode;
use Atom\View\Ast\TemplateNode;
use Atom\View\Ast\TextNode;
use Atom\View\Component\ComponentFactoryInterface;
use Atom\View\Component\AttributeBag;
use Atom\View\Component\Children;
use Atom\View\Component\ComponentInterface;
use Atom\View\Component\ComponentRegistry;
use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateFragment;
use Atom\View\Parser\ViewParser;
use Atom\View\Parser\ViewParseException;
use Atom\View\Render\HtmlString;
use Atom\View\Render\ViewContext;
use Atom\View\Render\ViewRenderException;
use Atom\View\Render\ViewRenderer;
use Atom\Integration\View\ViewServices;
use PHPUnit\Framework\TestCase;

final class ViewRendererTest extends TestCase
{
    public function testRendersTemplateFileWithTheSuppliedContext(): void
    {
        $page = new ArticleListPage();
        $page->title = "Articles & reviews";
        $html = (new ViewRenderer())->renderFile(
            __DIR__ . "/PageFixtures/ArticleListPage.atom.html",
            new ViewContext(["this" => $page])
        );

        $this->assertSame("<h1>Articles &amp; reviews</h1>", trim($html));
    }

    public function testMissingTemplateFileReportsItsPath(): void
    {
        $path = __DIR__ . "/PageFixtures/MissingTemplatePage.atom.html";
        $this->expectException(ViewParseException::class);
        $this->expectExceptionMessage($path);

        (new ViewRenderer())->renderFile($path);
    }

    public function testRendersTextElementsAndEscapedExpressions(): void
    {
        $html = $this->render('<h1>Hello {{ $name }}</h1>', [
            "name" => '<Edin>',
        ]);

        $this->assertSame('<h1>Hello &lt;Edin&gt;</h1>', $html);
    }

    public function testRendersIfElseIfAndElse(): void
    {
        $template = '@if($count > 10)<strong>Many</strong>@elseif($count > 0)<span>Some</span>@else<em>None</em>@endif';

        $this->assertSame('<strong>Many</strong>', $this->render($template, ["count" => 11]));
        $this->assertSame('<span>Some</span>', $this->render($template, ["count" => 2]));
        $this->assertSame('<em>None</em>', $this->render($template, ["count" => 0]));
    }

    public function testRendersConditionalAttributes(): void
    {
        $template = '<div><b :if="$count > 10">Many</b> <!-- branch --> <span :else-if="$count > 0">Some</span> <em :else>None</em></div>';
        foreach ([11 => "<b>Many</b>", 2 => "<span>Some</span>", 0 => "<em>None</em>"] as $count => $expected) {
            $this->assertSame("<div>" . $expected . "</div>", $this->render($template, ["count" => $count]));
        }
    }

    public function testRendersForEachAttributesAndEmptyIterators(): void
    {
        $template = '<b :foreach="$items as $key => $item">{{ $key }}:{{ $item }}</b> <i :empty>Empty</i>';
        $this->assertSame('<b>0:A</b><b>1:B</b>', $this->render($template, ["items" => ["A", "B"]]));
        $this->assertSame('<i>Empty</i>', $this->render($template, ["items" => new \EmptyIterator()]));
        $this->assertSame('<i>Empty</i>', $this->render('@foreach($items as $item)<b>{{ $item }}</b>@empty<i>Empty</i>@endforeach', ["items" => []]));
    }

    public function testRendersForEachWithScopedVariables(): void
    {
        $html = $this->render('@foreach($users as $id => $user)<span>{{ $id }}:{{ $user["name"] }}</span>@endforeach', [
            "users" => [
                10 => ["name" => "Ada"],
                20 => ["name" => "Linus"],
            ],
        ]);

        $this->assertSame('<span>10:Ada</span><span>20:Linus</span>', $html);
    }

    public function testRendersStaticBoundBooleanAndSpreadAttributes(): void
    {
        $html = $this->render('<button class="btn" :disabled="$disabled" :data-count="$count" {{ $attrs }}>Save</button>', [
            "disabled" => true,
            "count" => 3,
            "attrs" => [
                "title" => 'Save "now"',
                "hidden" => false,
            ],
        ]);

        $this->assertSame('<button class="btn" disabled data-count="3" title="Save &quot;now&quot;">Save</button>', $html);
    }

    public function testInterpolatesExpressionsInsideAttributeValues(): void
    {
        $html = $this->render('<a href="/articles/{{ $article->id }}" title="Open {{ $article->title }}">{{ $article->title }}</a>', [
            "article" => (object) [
                "id" => 42,
                "title" => 'Hello "Atom"',
            ],
        ]);

        $this->assertSame('<a href="/articles/42" title="Open Hello &quot;Atom&quot;">Hello &quot;Atom&quot;</a>', $html);
    }

    public function testDecodesOnlyLiteralAttributeEntitiesBeforeEscaping(): void
    {
        $this->assertSame(
            '<button title="A &amp; B &amp;amp; {{ $raw }}" data-bound="&amp;amp;">Save</button>',
            $this->render(
                '<button title="A &amp; B {{ $raw }} &#123;&#123; $raw &#125;&#125;" :data-bound="$raw">Save</button>',
                ["raw" => "&amp;"]
            )
        );
    }

    public function testRendersEmptyOptionValue(): void
    {
        $html = '<select name="choice"><option value="">Choose one</option></select>';

        $this->assertSame($html, $this->render($html));
    }

    public function testRejectsMalformedForwardedAttributeNames(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("ForwardButton", TestForwardButtonComponent::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid HTML attribute name.");
        $this->render('<ForwardButton {{ $attributes }}>Save</ForwardButton>', [
            "attributes" => ["data-label extra" => "Draft"],
        ], $registry);
    }

    public function testRejectsDirectiveOnNamedFragment(): void
    {
        $this->expectException(ViewParseException::class);
        $this->expectExceptionMessage("Named fragment <Card.Actions> must be a direct child of <Card>. Move the directive inside the fragment.");
        $this->render('<Card><Card.Actions :if="$show">Save</Card.Actions></Card>', ["show" => true]);
    }

    public function testRejectsNamedFragmentWrappedInDirective(): void
    {
        $this->expectException(ViewParseException::class);
        $this->expectExceptionMessage("Named fragment <Fragment> must be a direct child of <Card>. Move the directive inside the fragment.");
        $this->render('<Card>@if($show)<Fragment name="actions">Save</Fragment>@endif</Card>', ["show" => false]);
    }

    public function testEncodesHtmlStringsInAttributesAndRendersThemAsMarkupInContent(): void
    {
        $html = $this->render('<div :title="$label">{{ $label }}</div>', [
            "label" => new HtmlString('<em title="Review">Draft &amp; review</em>'),
        ]);

        $this->assertSame(
            '<div title="&lt;em title=&quot;Review&quot;&gt;Draft &amp;amp; review&lt;/em&gt;">'
                . '<em title="Review">Draft &amp; review</em></div>',
            $html
        );
    }

    public function testBoundNativeAttributeStringsStayLiteralAlongsideTemplateInterpolation(): void
    {
        $html = $this->render(
            '<span :title="$label" aria-label="Status: {{ $status }}" data-label="Label: {{ $label }}"></span>',
            ["label" => '{{ $status }} & "review"', "status" => "Draft"]
        );

        $this->assertSame(
            '<span title="{{ $status }} &amp; &quot;review&quot;" aria-label="Status: Draft" data-label="Label: {{ $status }} &amp; &quot;review&quot;"></span>',
            $html
        );
    }

    public function testRendersRegisteredComponentWithAssignedProperties(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("MyButton", TestButtonComponent::class);

        $html = $this->render('<MyButton text="Save" :disabled="$disabled" button-kind="primary" />', [
            "disabled" => true,
        ], $registry);

        $this->assertSame('<button class="primary" disabled>Save</button>', $html);
    }

    public function testCreatesComponentsUsingConfiguredFactory(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("FactoryComponent", TestFactoryRenderedComponent::class);

        $html = (new ViewRenderer(
            components: $registry,
            componentFactory: new TestComponentFactory()
        ))->render((new ViewParser())->parse('<FactoryComponent />'));

        $this->assertSame("factory", $html);
    }

    public function testViewServicesRenderComponentsWithScopedDependencies(): void
    {
        $injector = ServiceProviderRegistry::create()->add(ViewServices::class)->injector();
        $injector->get(ComponentRegistry::class)->register("FactoryComponent", TestFactoryRenderedComponent::class);

        $component = new TestFactoryRenderedComponent();
        $component->value = "scoped component";
        $context = new InjectionContext();
        $context->set(TestFactoryRenderedComponent::class, $component);

        $template = $injector->get(ViewParser::class)->parse("<FactoryComponent />");
        $html = $injector->get(ViewRenderer::class, $context)->render($template);

        $this->assertSame("scoped component", $html);
    }

    public function testAssignsOwnedFragmentsToDeclaredFragmentProperties(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Panel", TestPanelComponent::class);

        $html = $this->render(
            '<Panel title="Users"><Panel.Body><p>{{ $name }}</p></Panel.Body></Panel>',
            [],
            $registry
        );

        $this->assertSame('<section><h2>Users</h2><p>Ada</p></section>', $html);
    }

    public function testRendersNamedFragmentsAndLazilyUsesOutletFallback(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("OutletPanel", TestOutletPanelComponent::class);

        $this->assertSame(
            "<section><p>Ada: Delivery &amp; pickup</p></section>",
            $this->render(
                '<OutletPanel><Fragment name="body"><p>{{ $item }}: {{ $title }}</p></Fragment></OutletPanel>',
                ["title" => "Delivery & pickup"],
                $registry
            )
        );
        $this->assertSame("<section></section>", $this->render('<OutletPanel><Fragment name="body" /></OutletPanel>', [], $registry));
        $this->assertSame("<section><p>No delivery details.</p></section>", $this->render('<OutletPanel />', [], $registry));
        $this->assertSame(
            "<section>Legacy Ada</section>",
            $this->render('<OutletPanel><OutletPanel.Body>Legacy {{ $item }}</OutletPanel.Body></OutletPanel>', [], $registry)
        );
    }

    public function testFragmentOutletRendersFragmentValuesWithoutAWrappingElement(): void
    {
        $fragment = new Fragment(static fn(array $variables): string => "<b>" . $variables["item"] . "</b>");

        $this->assertSame("", $this->render('<FragmentOutlet />'));
        $this->assertSame(
            "<b>First</b><b>Second</b>",
            $this->render(
                '<FragmentOutlet :fragment="$fragment" :variables="[\'item\' => \'First\']" />'
                    . '<FragmentOutlet :fragment="$fragment" :variables="[\'item\' => \'Second\']" />',
                ["fragment" => $fragment]
            )
        );
    }

    public function testNamedFragmentRequiresALiteralName(): void
    {
        $this->expectException(\Atom\View\Parser\ViewParseException::class);
        $this->expectExceptionMessage("A named Fragment requires one literal name attribute.");
        $this->render('<OutletPanel><Fragment :name="$name" /></OutletPanel>');
    }

    public function testNamedFragmentMustBelongToAComponent(): void
    {
        $this->expectException(ViewRenderException::class);
        $this->render('<div><Fragment name="body" /></div>');
    }

    public function testAssignsAttributeAsFragmentWhenPropertyRequiresFragment(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Panel", TestPanelComponent::class);

        $html = $this->render('<Panel title="Hello" />', [], $registry);

        $this->assertSame('<section><h2>Hello</h2></section>', $html);
    }

    public function testAssignsDefaultContentToDeclaredContentProperty(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Frame", TestFrameComponent::class);

        $html = $this->render('<Frame>Hello {{ $name }}</Frame>', [
            "name" => "Edin",
        ], $registry);

        $this->assertSame('<div>Hello Edin</div>', $html);
    }

    public function testComponentCanRenderTemplateFragmentPreviewAndSource(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Example", TestExampleComponent::class);

        $html = $this->render('<Example><button class="danger">Delete</button></Example>', [], $registry);

        $this->assertSame(
            '<section><div><button class="danger">Delete</button></div><pre>&lt;button class=&quot;danger&quot;&gt;Delete&lt;/button&gt;</pre></section>',
            $html
        );
    }

    public function testParentComponentCanCollectDeclaredChildComponents(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Table", TestTableComponent::class);

        $html = $this->render('<Table><Column name="Title" /><Column name="Status" /></Table>', [], $registry);

        $this->assertSame('<table><th>Title</th><th>Status</th></table>', $html);
    }

    public function testForwardsUnknownAttributesThroughAttributeBag(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("ForwardButton", TestForwardButtonComponent::class);

        $html = $this->render('<ForwardButton id="save" disabled>Save</ForwardButton>', [], $registry);

        $this->assertSame('<button id="save" disabled>Save</button>', $html);
    }

    public function testRendersComponentReturningTemplateNode(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("TemplateReturn", TestTemplateReturnComponent::class);

        $html = $this->render('<TemplateReturn />', ["name" => "Ada"], $registry);

        $this->assertSame('<strong>Ada</strong>', $html);
    }

    public function testRendersComponentReturningViewNode(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("NodeReturn", TestNodeReturnComponent::class);

        $html = $this->render('<NodeReturn />', [], $registry);

        $this->assertSame('<span>Node</span>', $html);
    }

    public function testRendersComponentReturningViewNodeArray(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("ArrayReturn", TestArrayReturnComponent::class);

        $html = $this->render('<ArrayReturn />', [], $registry);

        $this->assertSame('Hello <strong>World</strong>', $html);
    }

    public function testThrowsHelpfulMessageForUnsupportedComponentRenderResult(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("BadReturn", TestUnsupportedReturnComponent::class);

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage("Component Atom\Tests\View\TestUnsupportedReturnComponent returned unsupported render result int");

        $this->render('<BadReturn />', [], $registry);
    }

    public function testThrowsHelpfulMessageForUnsupportedComponentRenderArrayItem(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("BadArrayReturn", TestUnsupportedArrayReturnComponent::class);

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage("returned an array with unsupported value at index '1': string");

        $this->render('<BadArrayReturn />', [], $registry);
    }

    public function testThrowsWhenRequiredComponentPropertyIsMissing(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("RequiredCard", TestRequiredCardComponent::class);

        $this->expectException(ViewRenderException::class);

        $this->render('<RequiredCard />', [], $registry);
    }

    public function testThrowsWhenForEachSourceIsNotIterable(): void
    {
        $this->expectException(ViewRenderException::class);

        $this->render('@foreach($users as $user)<span>{{ $user }}</span>@endforeach', [
            "users" => "nope",
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $source, array $variables = [], ?ComponentRegistry $components = null): string
    {
        return (new ViewRenderer(components: $components ?? new ComponentRegistry()))
            ->render((new ViewParser())->parse($source), $variables);
    }
}

final class TestButtonComponent implements ComponentInterface
{
    public string $text = "";
    public bool $disabled = false;
    public string $buttonKind = "";

    public function render(): string
    {
        return '<button class="' . $this->buttonKind . '"' . ($this->disabled ? " disabled" : "") . ">{$this->text}</button>";
    }
}

final class TestPanelComponent implements ComponentInterface
{
    public ?Fragment $title = null;
    public ?Fragment $body = null;

    public function render(): string
    {
        return "<section><h2>" . $this->title?->render() . "</h2>" . $this->body?->render(["name" => "Ada"]) . "</section>";
    }
}

final class TestOutletPanelComponent implements ComponentInterface
{
    public ?TemplateFragment $body = null;

    public function render(): TemplateNode
    {
        return (new ViewParser())->parse(
            '<section><FragmentOutlet :fragment="$this->body" :variables="[\'item\' => \'Ada\']">'
                . '<p>No delivery details.</p></FragmentOutlet></section>'
        );
    }
}

final class TestFrameComponent implements ComponentInterface
{
    public ?Fragment $content = null;

    public function render(): string
    {
        return "<div>" . $this->content?->render() . "</div>";
    }
}

final class TestExampleComponent implements ComponentInterface
{
    public ?TemplateFragment $content = null;

    public function render(): string
    {
        return "<section><div>" . $this->content?->render() . "</div><pre>" .
            htmlspecialchars($this->content?->source() ?? "", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            "</pre></section>";
    }
}

final class TestTableComponent implements ComponentInterface
{
    /** @var TestTableColumnComponent[] */
    #[Children("Column", TestTableColumnComponent::class)]
    public array $columns = [];
    public ?Fragment $content = null;

    public function render(): string
    {
        $columns = "";
        foreach ($this->columns as $column) {
            $columns .= $column->render();
        }

        return "<table>{$columns}" . $this->content?->render() . "</table>";
    }
}

final class TestTableColumnComponent implements ComponentInterface
{
    public string $name = "";

    public function render(): string
    {
        return "<th>{$this->name}</th>";
    }
}

final class TestRequiredCardComponent implements ComponentInterface
{
    public string $title;

    public function render(): string
    {
        return $this->title;
    }
}

final class TestForwardButtonComponent implements ComponentInterface
{
    public ?Fragment $content = null;
    public AttributeBag $attributes;

    public function render(): string
    {
        return "<button{$this->attributes->render()}>" . $this->content?->render() . "</button>";
    }
}

final class TestFactoryRenderedComponent implements ComponentInterface
{
    public string $value = "";

    public function render(): string
    {
        return $this->value;
    }
}

final class TestTemplateReturnComponent implements ComponentInterface
{
    public function render(): mixed
    {
        return new TemplateNode([
            new ElementNode("strong", children: [
                new ExpressionNode('$name'),
            ]),
        ]);
    }
}

final class TestNodeReturnComponent implements ComponentInterface
{
    public function render(): mixed
    {
        return new ElementNode("span", children: [
            new TextNode("Node"),
        ]);
    }
}

final class TestArrayReturnComponent implements ComponentInterface
{
    public function render(): mixed
    {
        return [
            new TextNode("Hello "),
            new ElementNode("strong", children: [
                new TextNode("World"),
            ]),
        ];
    }
}

final class TestUnsupportedReturnComponent implements ComponentInterface
{
    public function render(): mixed
    {
        return 42;
    }
}

final class TestUnsupportedArrayReturnComponent implements ComponentInterface
{
    public function render(): mixed
    {
        return [
            new TextNode("ok"),
            "bad",
        ];
    }
}

final class TestComponentFactory implements ComponentFactoryInterface
{
    public function create(string $className): ComponentInterface
    {
        $component = new $className();
        $component->value = "factory";

        return $component;
    }
}
