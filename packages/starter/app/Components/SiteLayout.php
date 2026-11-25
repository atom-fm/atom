<?php

declare(strict_types=1);

namespace App\Components;

use App\Models\User;
use App\Pages\AppPage;
use Atom\Identity\AuthenticatorInterface;
use Atom\Modules\Accounts\Components\LogoutForm;
use Atom\View\Component\Fragment;
use Atom\View\Component\TemplateComponent;
use Atom\View\Component\TemplateFragment;

final class SiteLayout extends TemplateComponent
{
    public AppPage $page;

    public Fragment|TemplateFragment|null $content = null;

    public function __construct(
        private readonly AuthenticatorInterface $authenticator,
        private readonly LogoutForm $logoutForm
    ) {
    }

    public function title(): string
    {
        return $this->page->title;
    }

    public function description(): string
    {
        return $this->page->description;
    }

    public function signedIn(): bool
    {
        return $this->authenticator->check();
    }

    public function userName(): string
    {
        $identity = $this->authenticator->identity();

        return $identity instanceof User && $identity->name !== ""
            ? $identity->name
            : (string) ($identity?->identifier() ?? "");
    }

    public function signOutForm(): string
    {
        $this->logoutForm->class = "site-sign-out";
        return $this->logoutForm->render();
    }
}
