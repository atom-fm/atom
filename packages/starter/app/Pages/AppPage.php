<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\SiteLayout;
use Atom\Page\Page;

abstract class AppPage extends Page
{
    public ?string $layout = SiteLayout::class;

    public string $title = "Atom Starter";

    public string $description = "A small, server-rendered website built with Atom Framework.";
}
