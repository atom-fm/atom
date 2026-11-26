<?php

declare(strict_types=1);

namespace App\Pages;

use Atom\Page\PageRoute;

#[PageRoute("/about", name: "about", title: "About - Atom Starter")]
final class AboutPage extends AppPage
{
    public string $title = "About - Atom Starter";
}
