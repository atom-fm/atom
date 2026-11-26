<?php

declare(strict_types=1);

namespace Showcase\Pages\Components;

use Atom\Components\ToastModel;
use Atom\Page\PageAction;
use Atom\Page\PageRoute;
use Atom\Page\State;
use Showcase\Pages\AppPage;

#[PageRoute("/components/notifications", name: "showcase.components.notifications")]
final class NotificationsPage extends AppPage
{
    public string $title = "Notifications - Atom Showcase";

    #[State]
    public ToastModel $toast;

    public function __construct()
    {
        $this->toast = new ToastModel();
    }

    #[PageAction("showToast")]
    public function showToast(string $variant = "success"): void
    {
        [$title, $message] = match ($variant) {
            "warning" => ["Review required", "Check the article details before publishing."],
            "danger" => ["Unable to save", "The article could not be saved. Please try again."],
            default => ["Article saved", "Your changes have been saved successfully."],
        };

        $this->toast->open(
            $message,
            $variant,
            $title
        );
    }

    #[PageAction("showSnackBar")]
    public function showSnackBar(string $variant = "success"): void
    {
        $this->flash(
            "Article was updated. You can undo this action.",
            $variant
        );
    }
}
