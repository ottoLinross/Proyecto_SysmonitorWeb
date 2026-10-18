<?php

namespace Tests\Feature;

use App\Http\Controllers\StorageController;
use App\Services\Storage\DfRunner;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\SystemUsersReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(SystemUsersReader::class)->shouldReceive('passwd')->andReturn('')->getMock()->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
        $this->mock(DfRunner::class)->shouldReceive('run')->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
    }

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
            ->assertSeeText('No se encontraron montajes activos.')
            ->assertSeeText('No se encontraron montajes configurados válidos.')
            ->assertDontSee('<table', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('<script', false);

    }
}
