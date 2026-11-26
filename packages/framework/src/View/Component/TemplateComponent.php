<?php

declare(strict_types=1);

namespace Atom\View\Component;

use Atom\Profiler\Profile;
use Atom\View\Templates;
use Atom\View\Parser\ViewParser;
use Atom\View\Ast\TemplateNode;
use Atom\View\Render\ViewRenderException;
use ReflectionObject;

abstract class TemplateComponent implements ComponentInterface
{
    public function render(): TemplateNode
    {
        $reflection = new ReflectionObject($this);
        $fileName = $reflection->getFileName();

        if ($fileName === false) {
            throw new ViewRenderException("Cannot locate template for component '{$reflection->getName()}'.");
        }

        $template = dirname($fileName) . DIRECTORY_SEPARATOR . $reflection->getShortName() . ".atom.html";
        if (!is_file($template)) {
            throw new ViewRenderException("Cannot find component template '{$template}'.");
        }

        return Templates::remember(
            $template,
            fn(): TemplateNode => Profile::measure(
                "view.parse",
                fn(): TemplateNode => (new ViewParser())->parseFile($template),
                ["component" => $reflection->getName(), "template" => $template]
            )
        );
    }
}
