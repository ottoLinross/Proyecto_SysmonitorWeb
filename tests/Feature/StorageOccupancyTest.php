<?php

namespace Tests\Feature;

use App\Services\Storage\DfRunner;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\SystemUsersReader;
use App\Services\Storage\WhoRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorageOccupancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FileExplorerService::class)->shouldReceive('browse')->andReturn(['available' => true, 'path' => '', 'parent' => null, 'entries' => [], 'message' => null]);
        $this->mock(WhoRunner::class)->shouldReceive('run')->andReturn('');
        $this->mock(SystemUsersReader::class)->shouldReceive('passwd')->andReturn('')->getMock()->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
        $this->mock(LsblkRunner::class)->shouldReceive('run')->once()->andReturn('{"blockdevices":[]}');
    }

    public static function percentages(): array
    {
        return [['0%', 0], ['47%', 47], ['100%', 100], ['00047%', 47]];
    }

    #[DataProvider('percentages')]
    public function test_accessible_bar_uses_validated_df_percentage(string $input, int $expected): void
    {
        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn("Filesystem Size Used Avail Use% Mounted on\n/dev/example 10G 1G 9G {$input} /media/My disk\n");
        $response = $this->get('/almacenamiento')->assertOk()
            ->assertSeeText($expected.'%')->assertSee('role="progressbar"', false)
            ->assertSee('aria-valuenow="'.$expected.'"', false)
            ->assertSee('aria-valuemin="0"', false)->assertSee('aria-valuemax="100"', false)
            ->assertSee('aria-label="Ocupación de /dev/example en /media/My disk"', false)
            ->assertSee('style="width: '.$expected.'%"', false)
            ->assertSeeText('No se encontraron dispositivos.')
            ->assertSeeText('Puntos de montaje')->assertSeeText('/dev/example')->assertSeeText('/media/My disk')
            ->assertSee('grid-column: 1 / -1', false)->assertSee('overflow-x: auto', false);
        $this->assertSame(6, substr_count($response->getContent(), '<th scope="col">'));
        $this->assertSame(1, substr_count($response->getContent(), 'role="progressbar"'));
    }

    public static function invalidPercentages(): array
    {
        return [['-1%'], ['101%'], ['9999999999999999999999999999%'], ['47.5%'], ['NaN%'], ['<script>']];
    }

    #[DataProvider('invalidPercentages')]
    public function test_invalid_percentage_is_rejected_without_inventing_a_bar(string $percentage): void
    {
        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn("Filesystem Size Used Avail Use% Mounted on\n/dev/example 10G 1G 9G {$percentage} /\n");
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo consultar el uso del espacio.')
            ->assertDontSee('role="progressbar"', false)->assertSeeText('No se encontraron dispositivos.');
    }

    public function test_no_results_does_not_invent_percentage(): void
    {
        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se encontraron sistemas de archivos montados.')
            ->assertDontSee('role="progressbar"', false);
    }
}
