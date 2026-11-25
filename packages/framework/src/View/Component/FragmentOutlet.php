<?php

declare(strict_types=1);

namespace Atom\View\Component;

use Atom\View\Ast\ElementNode;
use Atom\View\Render\ViewRenderException;

final class FragmentOutlet extends StructuralComponent
{
    public function renderStructure(ElementNode $node, StructuralComponentContext $context): string
    {
        $attributes = $context->attributes($node);
        $fragment = $attributes["fragment"] ?? null;

        if ($fragment === null) {
            return $context->render($node->children);
        }

        if (!$fragment instanceof Fragment && !$fragment instanceof TemplateFragment) {
            throw new ViewRenderException("FragmentOutlet expects a Fragment or TemplateFragment.");
        }

        $variables = $attributes["variables"] ?? [];
        if (!is_array($variables)) {
            throw new ViewRenderException("FragmentOutlet variables must be an array.");
        }

        return $fragment->render($variables);
    }
}
