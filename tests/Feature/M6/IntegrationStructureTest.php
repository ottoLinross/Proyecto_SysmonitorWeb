<?php

namespace Tests\Feature\M6;

use App\Http\Middleware\AuthenticateStorage;
use App\Services\M6\IntegrationOverview;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IntegrationStructureTest extends TestCase
{
    public function test_overview_identifies_the_existing_module_entry_points(): void
    {
        $overview = $this->app->make(IntegrationOverview::class);

        $this->assertSame([
            'M1' => ['label' => 'Procesos', 'route' => 'processes.index'],
            'M2' => ['label' => 'CPU y memoria', 'route' => 'cpu-memory.index'],
            'M3' => ['label' => 'Planificación', 'route' => 'scheduling.index'],
            'M4' => ['label' => 'Interbloqueos', 'route' => 'deadlocks.index'],
            'M5' => ['label' => 'Almacenamiento', 'route' => 'storage.index'],
        ], $overview->modules());
    }

    public function test_catalogue_links_resolve_to_existing_get_routes(): void
    {
        $expectedPaths = [
            'M1' => '/procesos',
            'M2' => '/cpu-memoria',
            'M3' => '/planificacion',
            'M4' => '/interbloqueos',
            'M5' => '/almacenamiento',
        ];

        foreach ($this->app->make(IntegrationOverview::class)->modules() as $module => $entry) {
            $route = Route::getRoutes()->getByName($entry['route']);

            $this->assertNotNull($route);
            $this->assertContains('GET', $route->methods());
            $this->assertSame($expectedPaths[$module], route($entry['route'], [], false));
        }
    }

    public function test_resolving_overview_preserves_routes_and_storage_protection(): void
    {
        $routes = Route::getRoutes()->getRoutes();

        $this->app->make(IntegrationOverview::class)->modules();

        $this->assertSame($routes, Route::getRoutes()->getRoutes());
        $this->assertContains(
            AuthenticateStorage::class,
            Route::getRoutes()->getByName('storage.index')->gatherMiddleware(),
        );
    }
}
