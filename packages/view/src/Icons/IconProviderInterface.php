<?php

declare(strict_types=1);

namespace Atom\View\Icons;

interface IconProviderInterface
{
    public function icons(IconRegistry $icons): void;
}
