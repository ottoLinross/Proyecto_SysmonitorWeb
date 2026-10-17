<?php

namespace Tests\Feature;

use App\Http\Controllers\StorageController;
use App\Services\Storage\LsblkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StorageTest extends TestCase
{
    public function test_storage_route_uses_the_dedicated_controller(): void
    {
        $route = Route::getRoutes()->getByName('storage.index');

        $this->assertNotNull($route);
        $this->assertSame('almacenamiento', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(StorageController::class.'@index', $route->getActionName());
        $this->assertSame('/almacenamiento', route('storage.index', [], false));
    }

    public function test_page_renders_without_database_or_real_process_calls(): void
    {
        DB::shouldReceive('connection')->never();
        $this->mock(LsblkRunner::class)->shouldReceive('run')->once()->andReturn('{"blockdevices":[]}');

        $this->get(route('storage.index', [], false))
            ->assertOk()
            ->assertViewIs('storage.index')
            ->assertSee('<html lang="es">', false)
            ->assertSee('<title>Almacenamiento — SysMonitor</title>', false)
            ->assertSeeText('SysMonitor · M5')
            ->assertSee('<h1>Almacenamiento</h1>', false)
            ->assertSeeText('Discos y particiones del servidor Debian.')
            ->assertSeeText('No se encontraron dispositivos.')
            ->assertSeeText('Dispositivos de almacenamiento')
            ->assertSeeText('Uso del espacio')
            ->assertSeeText('Puntos de montaje')
            ->assertSeeText('Pendiente de implementación. No se han consultado datos.')
            ->assertDontSee('<table', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('<script', false);

    }
}
