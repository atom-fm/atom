<?php

declare(strict_types=1);

namespace App;

use App\Controllers\PokemonController;
use App\Controllers\TeamController;
use Atom\Database\DatabaseServices;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Http\Response;
use Atom\Module\ModuleRegistry;
use Atom\Modules\ApiExplorer\ApiExplorer;
use Atom\Modules\Client\Client;
use Atom\Components\Components;
use Atom\Router\Route;

final class Application extends \Atom\Application
{
    protected function rootPath(): string
    {
        return dirname(__DIR__);
    }

    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers->add(DatabaseServices::fromConfig($this->getConfig(), $this->getPaths()));
    }

    protected function modules(ModuleRegistry $modules): void
    {
        $modules
            ->add(Client::module())
            ->add(Components::module())
            ->add(ApiExplorer::module(), "/atom/api");
    }

    protected function bootstrap(Injector $injector): void
    {
        Route::get("/", static fn (Response $response): Response => $response->redirect("/atom/api"));
        Route::attach(PokemonController::class);
        Route::attach(TeamController::class);
    }
}
