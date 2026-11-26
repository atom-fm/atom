<?php

declare(strict_types=1);

namespace Atom\Components;

use Atom\Page\PageAction;

final class DrawerModel
{
    public bool $show = false;

    public mixed $value = null;

    #[PageAction]
    public function open(mixed $value = null): void
    {
        $this->show = true;
        $this->value = $value;
    }

    #[PageAction]
    public function close(): void
    {
        $this->show = false;
    }
}
