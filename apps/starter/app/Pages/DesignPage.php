<?php

declare(strict_types=1);

namespace App\Pages;

use Atom\Page\PageRoute;

#[PageRoute("/design", name: "design", title: "Design System - Atom Starter")]
final class DesignPage extends AppPage
{
    public string $title = "Design System - Atom Starter";

    public string $description = "A live catalogue of the starter's reusable colors, typography, spacing, and interface patterns.";
}
