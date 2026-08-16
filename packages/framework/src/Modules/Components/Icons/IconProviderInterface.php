<?php

declare(strict_types=1);

namespace Atom\Modules\Components\Icons;

interface IconProviderInterface
{
    public function icons(IconRegistry $icons): void;
}
