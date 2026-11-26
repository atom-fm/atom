<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\View\Component\ComponentInterface;
use Atom\View\Html;

final class ComponentsScripts implements ComponentInterface
{
    public string $resourcePath = Components::DEFAULT_RESOURCE_PATH;
    public string $version = Components::SCRIPTS_VERSION;

    public function render(): string
    {
        $url = rtrim($this->resourcePath, "/") . "/atom-controls.js";
        if ($this->version !== "") {
            $url .= "?v=" . rawurlencode($this->version);
        }

        return Html::tag("script", ["type" => "module", "src" => $url]);
    }
}
