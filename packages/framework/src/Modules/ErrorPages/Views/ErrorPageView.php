<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorPages\Views;

use Atom\Modules\ErrorPages\ErrorPage;
use Atom\Page\Page;

final class ErrorPageView extends Page
{
    public ?string $layout = "ErrorPages.Layout";

    /** @param array<string, int|string> $diagnostics */
    public function __construct(
        public readonly ErrorPage $error,
        public readonly array $diagnostics
    ) {
    }
}
