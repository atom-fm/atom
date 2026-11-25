<?php

declare(strict_types=1);

namespace Atom\View\Style;

use Atom\Http\Response;

final readonly class ComponentStylesController
{
    public function __construct(private StyleComposer $composer)
    {
    }

    public function styles(): Response
    {
        return (new Response())->header("Content-Type", "text/css; charset=utf-8")
            ->header("Cache-Control", "no-cache")
            ->content($this->composer->compose());
    }
}
