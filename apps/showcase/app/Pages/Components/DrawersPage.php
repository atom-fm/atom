<?php

declare(strict_types=1);

namespace Showcase\Pages\Components;

use Atom\Modules\Components\DrawerModel;
use Atom\Page\PageRoute;
use Atom\Page\State;
use Showcase\Pages\AppPage;

#[PageRoute("/components/drawers", name: "showcase.components.drawers")]
final class DrawersPage extends AppPage
{
    public string $title = "Drawers - Atom Showcase";

    #[State]
    public DrawerModel $detailsDrawer;

    #[State]
    public DrawerModel $filtersDrawer;

    public function __construct()
    {
        $this->detailsDrawer = new DrawerModel();
        $this->filtersDrawer = new DrawerModel();
    }
}
