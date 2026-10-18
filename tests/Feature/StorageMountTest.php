<?php

namespace Tests\Feature;

use App\Services\Storage\DfRunner;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\MountService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StorageMountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LsblkRunner::class)->shouldReceive('run')->andReturn('{"blockdevices":[]}');
        $this->mock(DfRunner::class)->shouldReceive('run')->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
    }

    public function test_multiple_active_and_configured_mounts_are_separate_and_escaped(): void
    {
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn(json_encode(['filesystems' => [
            ['source' => '/dev/root', 'target' => '/', 'fstype' => 'ext4'],
            ['source' => 'tmpfs', 'target' => '/<script>alert(1)</script>', 'fstype' => 'tmpfs'],
        ]]));
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn("# comment\n\n  # indented\nUUID=abc / ext4 defaults 0 1\nserver:/share /mnt/My\\040disk nfs ro\nswapfile none swap sw 0 0 # comment\ninvalid\nx y z options bad 1\n");
        $result = app(MountService::class)->configured();
        $this->assertCount(3, $result['mounts']);
        $this->assertSame('/mnt/My disk', $result['mounts'][1]['target']);
        $this->get('/almacenamiento?command=evil')->assertOk()
            ->assertSeeText('Montajes activos (findmnt)')->assertSeeText('Montajes configurados (/etc/fstab)')
            ->assertSeeText('/dev/root')->assertSeeText('UUID=abc')->assertSeeText('/mnt/My disk')
            ->assertSeeText('swapfile')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }

    public static function invalidOutputs(): array
    {
        return [[''], ['{'], ['{}'], ['{"filesystems":{}}'], ['{"filesystems":[null]}'], ['{"filesystems":[{"source":[],"target":"/","fstype":"ext4"}]}']];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_findmnt_output(string $output): void
    {
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn($output);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudieron consultar los montajes activos.')
            ->assertSeeText('No se encontraron montajes configurados válidos.');
    }

    public function test_failures_are_controlled_and_preserve_previous_sections(): void
    {
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andThrow(new \RuntimeException('secret'));
        $this->mock(FstabReader::class)->shouldReceive('read')->andThrow(new \RuntimeException('secret'));
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudieron consultar los montajes activos.')
            ->assertSeeText('No se pudo leer /etc/fstab.')->assertDontSeeText('secret')
            ->assertSeeText('No se encontraron dispositivos.')->assertSeeText('No se encontraron sistemas de archivos montados.');
    }

    public function test_empty_results(): void
    {
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se encontraron montajes activos.')
            ->assertSeeText('No se encontraron montajes configurados válidos.');
    }

    public function test_fixed_findmnt_command_and_timeout(): void
    {
        $runner = new class extends FindmntRunner
        {
            public function inspect(): Process
            {
                return $this->createProcess();
            }
        };
        $this->assertSame("'/usr/bin/findmnt' '--json' '--list' '--output' 'SOURCE,TARGET,FSTYPE'", $runner->inspect()->getCommandLine());
        $this->assertSame(5.0, $runner->inspect()->getTimeout());
        $this->assertSame(0, (new \ReflectionMethod(FindmntRunner::class, 'run'))->getNumberOfParameters());
    }

    public function test_partition_tables_preserve_hierarchy(): void
    {
        $base = ['name' => 'disk', 'path' => '/dev/example', 'type' => 'disk', 'size' => 100, 'fstype' => null, 'mountpoints' => null, 'model' => null];
        $this->mock(LsblkRunner::class)->shouldReceive('run')->andReturn(json_encode(['blockdevices' => [
            $base + ['pttype' => 'gpt', 'children' => [$base + ['pttype' => null]]],
            $base + ['pttype' => 'dos'], $base,
        ]]));
        $this->get('/almacenamiento')->assertOk()->assertSeeText('GPT')->assertSeeText('MBR (dos)')
            ->assertSeeText('No reportado')->assertSeeText('Tabla de particiones (PTTYPE)');
    }
}
