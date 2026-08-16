<?php

declare(strict_types=1);

namespace App;

use App\Providers\AccountsServiceProvider;
use Atom\Database\DatabaseServices;
use Atom\Di\ServiceProviderRegistry;
use Atom\Module\ModuleRegistry;
use Atom\Modules\Accounts\Accounts;
use Atom\Page\PageRegistry;

final class Application extends \Atom\Application
{
    protected function rootPath(): string
    {
        return dirname(__DIR__);
    }

    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers
            ->add(DatabaseServices::fromConfig($this->getConfig(), $this->getPaths()))
            ->add(AccountsServiceProvider::class);
    }

    protected function modules(ModuleRegistry $modules): void
    {
        $modules->add(Accounts::module(), "/account");
    }

    protected function pages(PageRegistry $pages): void
    {
        $pages->directory("@app/Pages");
    }
}
