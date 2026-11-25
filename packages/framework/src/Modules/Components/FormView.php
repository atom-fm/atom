<?php

declare(strict_types=1);

namespace Atom\Modules\Components;

use Atom\Page\Form as PageForm;
use Atom\Profiler\Profile;
use Atom\View\Ast\AttributeSpreadNode;
use Atom\View\Ast\ElementNode;
use Atom\View\Ast\TemplateNode;
use Atom\View\Component\StructuralComponent;
use Atom\View\Component\StructuralComponentContext;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ViewRenderException;
use Atom\View\Templates;
use ReflectionObject;

/** Renders a form's adjacent template in the current page/component registry. */
final class FormView extends StructuralComponent
{
    public function renderStructure(ElementNode $node, StructuralComponentContext $context): string
    {
        $attributes = $context->attributes($node);
        $form = $attributes["form"] ?? null;
        if (!$form instanceof PageForm) {
            throw new ViewRenderException("FormView requires a form extending " . PageForm::class . ".");
        }
        if ($node->children !== []) {
            throw new ViewRenderException("FormView renders the form template; use a self-closing FormView element.");
        }
        unset($attributes["form"]);
        $attributes = ["method" => "post", "csrf" => true, ...$attributes, "model" => $form];
        $template = $this->template($form);

        return $context->render([
            new ElementNode("Form", [new AttributeSpreadNode('$formViewAttributes')], $template->children),
        ], [
            "this" => $form,
            "form" => $form,
            "model" => $form,
            "formViewAttributes" => $attributes,
        ]);
    }

    private function template(PageForm $form): TemplateNode
    {
        $reflection = new ReflectionObject($form);
        $file = $reflection->getFileName();
        if ($file === false) {
            throw new ViewRenderException("Cannot locate template for form '{$reflection->getName()}'.");
        }
        $path = $form->template() ?? $reflection->getShortName() . ".atom.html";
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = dirname($file) . DIRECTORY_SEPARATOR . $path;
        }
        if (!is_file($path)) {
            throw new ViewRenderException("Cannot find form template '{$path}' for '{$reflection->getName()}'.");
        }

        return Templates::remember($path, function () use ($path, $reflection): TemplateNode {
            $source = file_get_contents($path);
            if ($source === false) {
                throw new ViewRenderException("Cannot read form template '{$path}'.");
            }
            return Profile::measure(
                "view.parse",
                fn(): TemplateNode => (new ViewParser())->parse($source),
                ["form" => $reflection->getName(), "template" => $path]
            );
        });
    }
}
