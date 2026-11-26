<?php

declare(strict_types=1);

namespace App;

use App\Components\Feature;
use App\Components\FeatureGrid;
use App\Components\PageHero;
use App\Components\SectionIntro;
use App\Providers\AccountsServiceProvider;
use App\Providers\DocumentationServiceProvider;
use Atom\Database\DatabaseServices;
use Atom\Di\ServiceProviderRegistry;
use Atom\Module\ModuleRegistry;
use Atom\Modules\Accounts\Accounts;
use Atom\Modules\Accounts\AccountsOptions;
use Atom\Modules\Client\Client;
use Atom\Page\PageRegistry;
use Atom\View\Component\ComponentRegistry;

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
            ->add(AccountsServiceProvider::class)
            ->add(DocumentationServiceProvider::class);
    }

    protected function modules(ModuleRegistry $modules): void
    {
        $modules->add(Client::module());
        $modules->add(Accounts::module(new AccountsOptions(
            themeStylesheet: "/style.css?v=6"
        )), "/account");
    }

    protected function components(ComponentRegistry $components): void
    {
        $components
            ->register("PageHero", PageHero::class)
            ->register("SectionIntro", SectionIntro::class)
            ->register("FeatureGrid", FeatureGrid::class)
            ->register("Feature", Feature::class);
    }

    protected function pages(PageRegistry $pages): void
    {
        $pages->directory("@app/Pages");
    }
}
