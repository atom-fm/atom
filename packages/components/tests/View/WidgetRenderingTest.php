<?php

declare(strict_types=1);

namespace Atom\Tests\View;

use Atom\Components\Button;
use Atom\Components\Card;
use Atom\View\Component\ComponentRegistry;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class WidgetRenderingTest extends TestCase
{
    public function testRendersConditionInsideNamedFragment(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Card", Card::class);
        $source = '<Card><Card.Actions><span :if="$show">Save</span></Card.Actions></Card>';

        $this->assertStringContainsString('<footer class="atom-card__actions"><span>Save</span></footer>', $this->render($source, ["show" => true], $registry));
        $this->assertStringNotContainsString("Save", $this->render($source, ["show" => false], $registry));
    }

    public function testBoundAttributeStringsStayLiteralInNestedComponents(): void
    {
        $registry = new ComponentRegistry();
        $registry->register("Card", Card::class);
        $registry->register("Button", Button::class);

        $html = $this->render(
            '<Card :title="$label"><Card.Actions><Button :text="$label" :aria-label="$label" '
                . 'data-status="Status: {{ $status }}" /></Card.Actions></Card>',
            ["label" => '{{ $status }} & "review"', "status" => "Draft"],
            $registry
        );

        $this->assertStringContainsString('<h3 class="atom-card__title">{{ $status }} &amp; &quot;review&quot;</h3>', $html);
        $this->assertStringContainsString(
            '<button type="button" class="atom-button" aria-label="{{ $status }} &amp; &quot;review&quot;" data-status="Status: Draft">'
                . '{{ $status }} &amp; &quot;review&quot;</button>',
            $html
        );
    }

    private function render(string $source, array $variables = [], ?ComponentRegistry $components = null): string
    {
        return (new ViewRenderer(components: $components ?? new ComponentRegistry()))
            ->render((new ViewParser())->parse($source), $variables);
    }
}
