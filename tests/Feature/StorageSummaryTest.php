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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StorageSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FileExplorerService::class)->shouldReceive('browse')->with('sub')->andReturn([
            'available' => true, 'path' => 'sub', 'parent' => '', 'entries' => [], 'message' => null,
        ]);
        $partition = $this->device('sda1', 'part');
        $this->mock(LsblkRunner::class)->shouldReceive('run')->withNoArgs()->byDefault()->andReturn(json_encode(['blockdevices' => [
            $this->device('sda', 'disk', [$partition]),
            $this->device('sdb', 'disk', [$partition]),
            $this->device('loop0', 'loop'),
        ]]));
        $this->mock(DfRunner::class)->shouldReceive('run')->withNoArgs()->byDefault()->andReturn("Filesystem Size Used Avail Use% Mounted on\n/dev/sda1 10G 4G 6G 40% /\n/dev/sda1 10G 4G 6G 40% /bind\n");
        $this->mock(FindmntRunner::class)->shouldReceive('run')->withNoArgs()->byDefault()->andReturn(json_encode(['filesystems' => [
            ['source' => '/dev/sda1', 'target' => '/', 'fstype' => 'ext4'],
            ['source' => '/dev/sda1', 'target' => '/bind', 'fstype' => 'ext4'],
            ['source' => '/dev/sda1', 'target' => '/bind', 'fstype' => 'ext4'],
        ]]));
        $this->mock(FstabReader::class)->shouldReceive('read')->byDefault()->andReturn('');
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('passwd')->byDefault()->andReturn("root:x:0:0::/root:/bin/bash\nuser:x:1000:1000::/home/user:/bin/bash\n");
        $reader->shouldReceive('loginDefs')->byDefault()->andReturn('UID_MIN 1000');
        $this->mock(WhoRunner::class)->shouldReceive('run')->withNoArgs()->byDefault()->andReturn('');
    }

    private function device(string $name, string $type, array $children = []): array
    {
        return ['name' => $name, 'path' => '/dev/'.$name, 'type' => $type, 'size' => 100000,
            'fstype' => null, 'mountpoints' => null, 'model' => null, 'children' => $children];
    }

    public function test_http_payloads_cannot_change_commands_or_sources(): void
    {
        $query = http_build_query([
            'path' => 'sub', 'command' => ['sh', '-c', 'touch /tmp/forbidden'],
            'args' => '; id | cat', 'source' => '/etc/shadow', 'action' => 'download',
            'file' => '../../etc/passwd', 'root' => '/',
        ]);
        $this->get('/almacenamiento?'.$query)->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['disks'] === 2 && $summary['users'] === 2)
            ->assertHeaderMissing('Content-Disposition')->assertDontSeeText('touch /tmp/forbidden')
            ->assertDontSeeText('/etc/shadow');
    }

    public function test_summary_is_consistent_deduplicated_and_does_not_sum_capacities(): void
    {
        $response = $this->get('/almacenamiento?path=sub&action=delete');
        $response->assertOk()->assertViewHas('summary', function (array $summary): bool {
            $this->assertSame(2, $summary['disks']);
            $this->assertSame(1, $summary['partitions']);
            $this->assertSame(2, $summary['mounts']);
            $this->assertSame(2, $summary['users']);
            $this->assertSame(0, $summary['sessions']);
            $this->assertSame(['Disponible'], array_values(array_unique($summary['sources'])));

            return true;
        })->assertSeeText('No hay sesiones activas reportadas.')
            ->assertSeeText('No disponible como total global fiable.')
            ->assertSee(route('storage.index', ['path' => 'sub']), false)
            ->assertDontSee('<form', false)->assertDontSee('<script', false);
    }

    public function test_zero_disks_and_no_sessions_are_available_empty_results(): void
    {
        $this->mock(LsblkRunner::class)->shouldReceive('run')->once()->andReturn('{"blockdevices":[]}');
        $this->get('/almacenamiento?path=sub')->assertOk()->assertViewHas('summary', function (array $summary): bool {
            $this->assertSame(0, $summary['disks']);
            $this->assertSame(0, $summary['partitions']);
            $this->assertSame(0, $summary['sessions']);
            $this->assertSame('Disponible', $summary['sources']['devices']);
            $this->assertSame('Disponible', $summary['sources']['sessions']);

            return true;
        })->assertSeeText('No se encontraron dispositivos.');
    }

    public static function failingSources(): array
    {
        return [
            [LsblkRunner::class, 'run', 'devices', 'disks'],
            [DfRunner::class, 'run', 'usage', null],
            [FindmntRunner::class, 'run', 'mounts', 'mounts'],
            [FstabReader::class, 'read', 'configured', null],
            [SystemUsersReader::class, 'passwd', 'users', 'users'],
            [WhoRunner::class, 'run', 'sessions', 'sessions'],
        ];
    }

    #[DataProvider('failingSources')]
    public function test_failures_are_independent_and_hide_internal_details(string $runner, string $method, string $source, ?string $counter): void
    {
        $mock = $this->mock($runner);
        $mock->shouldReceive($method)->once()->andThrow(new \LogicException('SECRET /private/internal.php Stack trace #0'));
        if ($runner === SystemUsersReader::class) {
            $mock->shouldReceive('loginDefs')->once()->andReturn('UID_MIN 1000');
        }
        $this->get('/almacenamiento?path=sub')->assertOk()->assertViewHas('summary', function (array $summary) use ($source, $counter): bool {
            $this->assertSame('Error de lectura', $summary['sources'][$source]);
            foreach ($summary['sources'] as $key => $state) {
                if ($key !== $source) {
                    $this->assertSame('Disponible', $state);
                }
            }
            if ($counter !== null) {
                $this->assertNull($summary[$counter]);
            }

            return true;
        })->assertSeeText('Error de lectura')->assertSeeText('El directorio está vacío.')
            ->assertDontSeeText('SECRET')->assertDontSeeText('/private/internal.php')->assertDontSeeText('Stack trace');
    }

    public function test_missing_command_is_unavailable_and_invalid_output_is_a_read_error(): void
    {
        $process = new Process(['/missing/storage-command']);
        $process->run();
        $this->mock(LsblkRunner::class)->shouldReceive('run')->once()->andThrow(new ProcessFailedException($process));
        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn('invalid internal output');
        $this->get('/almacenamiento?path=sub')->assertOk()->assertViewHas('summary', function (array $summary): bool {
            $this->assertSame('No disponible', $summary['sources']['devices']);
            $this->assertSame('Error de lectura', $summary['sources']['usage']);
            $this->assertSame('Disponible', $summary['sources']['sessions']);

            return true;
        })->assertDontSeeText('/missing/storage-command')->assertDontSeeText('invalid internal output');
    }
}
