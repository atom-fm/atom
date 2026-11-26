<?php

declare(strict_types=1);

use Atom\View\Component\ComponentRegistry;
use Atom\View\Render\ViewRenderer;
use ViewLab\Label;
use ViewLab\Panel;

require __DIR__ . "/vendor/autoload.php";

$components = (new ComponentRegistry())
    ->register("LabPanel", Panel::class)
    ->register("LabLabel", Label::class)
    ->seal();

return new ViewRenderer(components: $components);
