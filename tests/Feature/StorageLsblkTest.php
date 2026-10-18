<?php

namespace Tests\Feature;

use App\Services\Storage\DfRunner;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\StorageOverviewService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StorageLsblkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
        $this->mock(DfRunner::class)->shouldReceive('run')->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
    }

    private static function device(array $overrides = []): array
    {
        return array_replace(['name' => 'disk alpha', 'path' => '/dev/disk alpha', 'type' => 'disk', 'size' => 12345, 'fstype' => null, 'mountpoints' => null, 'model' => 'Model with spaces'], $overrides);
    }

    public function test_hierarchy_nulls_loops_and_spaces_are_preserved_and_rendered(): void
    {
        $devices = [
            self::device(['children' => [self::device(['name' => 'partition one', 'type' => 'part', 'model' => null, 'mountpoints' => [null, '/media/My disk'], 'fstype' => 'ext4'])]]),
            self::device(['name' => 'empty disk']),
            self::device(['name' => 'loop9', 'type' => 'loop', 'size' => null]),
            self::device(['name' => '<script>alert(1)</script>']),
        ];
        $this->mock(LsblkRunner::class)->shouldReceive('run')->twice()->andReturn(json_encode(['blockdevices' => $devices]));
        $result = app(StorageOverviewService::class)->devices();
        $this->assertTrue($result['available']);
        $this->assertCount(4, $result['devices']);
        $this->assertSame('partition one', $result['devices'][0]['children'][0]['name']);
        $this->assertSame([], $result['devices'][1]['children']);
        $this->assertSame('loop', $result['devices'][2]['type']);
        $this->assertNull($result['devices'][2]['size']);
        $this->get('/almacenamiento?command=evil&args=--help')->assertOk()
            ->assertSeeText('disk alpha')->assertSeeText('partition one')->assertSeeText('empty disk')
            ->assertSeeText('loop9')->assertSeeText('Model with spaces')->assertSeeText('/media/My disk')
            ->assertSeeText('12345')->assertSeeText('ext4')->assertSeeText('—')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public static function invalidOutputs(): array
    {
        return [['{'], ['{}'], ['{"blockdevices":null}'], ['{"blockdevices":{}}'], ['{"blockdevices":[{}]}'], [json_encode(['blockdevices' => [self::device(['size' => []])]])], [json_encode(['blockdevices' => [self::device(['mountpoints' => [42]])]])], [json_encode(['blockdevices' => [self::device(['children' => 'bad'])]])]];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_controlled(string $output): void
    {
        $this->mock(LsblkRunner::class)->shouldReceive('run')->once()->andReturn($output);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo consultar la información de almacenamiento.');
    }

    public function test_command_failure_does_not_expose_details(): void
    {
        $this->mock(LsblkRunner::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('internal sensitive detail'));
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo consultar la información de almacenamiento.')
            ->assertDontSeeText('internal sensitive detail');
    }

    public function test_runner_has_fixed_arguments_and_timeout(): void
    {
        $runner = new class extends LsblkRunner
        {
            public function inspect(): Process
            {
                return $this->createProcess();
            }
        };
        $process = $runner->inspect();
        $this->assertSame("'/usr/bin/lsblk' '-J' '-b' '-o' 'NAME,PATH,TYPE,SIZE,FSTYPE,MOUNTPOINTS,PTTYPE,MODEL'", $process->getCommandLine());
        $this->assertSame(5.0, $process->getTimeout());
        $this->assertSame(0, (new \ReflectionMethod(LsblkRunner::class, 'run'))->getNumberOfParameters());
    }
}
