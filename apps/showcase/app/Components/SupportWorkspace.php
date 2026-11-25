<?php

declare(strict_types=1);

namespace Showcase\Components;

use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;

final class SupportWorkspace extends TemplateComponent
{
    public ?Fragment $content = null;
    public string $screen = "Inbox";
    public string $status = "";
    public bool $inbox = false;
}
