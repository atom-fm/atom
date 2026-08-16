<?php

declare(strict_types=1);

namespace App\Pages;

use App\Models\User;
use Atom\Identity\AuthenticatorInterface;
use Atom\Page\Page;
use Atom\Page\PageRoute;

#[PageRoute("/", name: "home")]
final class HomePage extends Page
{
    public string $name = "";

    public function get(AuthenticatorInterface $authenticator): void
    {
        $identity = $authenticator->identity();
        $this->name = $identity instanceof User ? $identity->name : "";
    }
}
