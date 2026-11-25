<?php

declare(strict_types=1);

namespace Atom\View\Component;

use Atom\View\Ast\ElementNode;
use Atom\View\Ast\FragmentNode;
use Atom\View\Ast\ViewNodeInterface;
use Atom\View\Render\ViewContext;
use Closure;

final readonly class StructuralComponentContext
{
    /** @param Closure(array<int, ViewNodeInterface>, ViewContext): string $renderer */
    public function __construct(
        private ComponentHydrator $hydrator,
        private ViewContext $context,
        private Closure $renderer
    ) {
    }

    /** @return array<string, mixed> */
    public function attributes(ElementNode|FragmentNode $node): array
    {
        return $this->hydrator->evaluateAttributes($node, $this->context);
    }

    /**
     * @param ViewNodeInterface[] $nodes
     * @param array<string, mixed> $variables
     */
    public function render(array $nodes, array $variables = []): string
    {
        return ($this->renderer)($nodes, $this->context->with($variables));
    }
}
