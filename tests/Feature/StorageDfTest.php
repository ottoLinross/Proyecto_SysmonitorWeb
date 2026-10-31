<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Storage\DfRunner;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\FilesystemUsageService;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\SystemUsersReader;
use App\Services\Storage\WhoRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\StorageTestCase;

class StorageDfTest extends StorageTestCase
{
    private const HEADER = "Filesystem Size Used Avail Use% Mounted on\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FileExplorerService::class)->shouldReceive('browse')->andReturn(['available' => true, 'path' => '', 'parent' => null, 'entries' => [], 'message' => null]);
        $this->mock(WhoRunner::class)->shouldReceive('run')->andReturn('');
        $this->mock(SystemUsersReader::class)->shouldReceive('passwd')->andReturn('')->getMock()->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
        $this->mock(LsblkRunner::class)->shouldReceive('run')->andReturn('{"blockdevices":[{"name":"disk-preserved","path":"/dev/example","type":"disk","size":123,"fstype":null,"mountpoints":null,"model":null}]}');
    }

    public static function validOutputs(): array
    {
        return [
            'one filesystem' => ["/dev/example 10G 1G 9G 10% /\n", 1, '/'],
            'multiple and valid mount characters' => ["tmpfs 1G 0 1G 0% /run\n/dev/example 10G 10G 0 100% /media/My disk-á_1:(backup)\n", 2, '/media/My disk-á_1:(backup)'],
            'spaces in filesystem' => ["server:/my share 2.5T 1.0T 1.5T 40% /mnt/shared folder\n", 1, '/mnt/shared folder'],
        ];
    }

    #[DataProvider('validOutputs')]
    public function test_parses_and_renders_realistic_fixtures(string $rows, int $count, string $mount): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->mock(DfRunner::class)->shouldReceive('run')->twice()->andReturn(self::HEADER.$rows);
        $result = app(FilesystemUsageService::class)->usage();
        $this->assertTrue($result['available']);
        $this->assertCount($count, $result['filesystems']);
        $this->assertSame($mount, $result['filesystems'][$count - 1]['mountpoint']);
        $this->assertSame(['filesystem', 'size', 'used', 'available', 'use_percent', 'mountpoint'], array_keys($result['filesystems'][0]));
        $response = $this->get('/almacenamiento?command=evil&args=--help')->assertOk()
            ->assertSeeText($mount)->assertSeeText('disk-preserved')->assertSee('<th scope="col">', false)
            ->assertSee('<caption>', false);
        foreach ($result['filesystems'] as $filesystem) {
            foreach ($filesystem as $value) {
                $response->assertSeeText($value);
            }
        }
    }

    public static function invalidOutputs(): array
    {
        return [[''], ['not df'], [self::HEADER.'/dev/example 10G 1G'], [self::HEADER.'/dev/example 10G 1G 9G invalid /'], [self::HEADER.'/dev/example bad 1G 9G 10% /'], [self::HEADER."/dev/example 10G 1G 9G 10% /\nbroken\n"]];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_a_controlled_error(string $output): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn($output);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo consultar el uso del espacio.')
            ->assertDontSeeText('No se encontraron sistemas de archivos montados.')->assertSeeText('disk-preserved');
    }

    public function test_header_only_means_no_results(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn(self::HEADER);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se encontraron sistemas de archivos montados.')
            ->assertDontSeeText('No se pudo consultar el uso del espacio.');
    }

    public function test_failure_hides_exception_details_and_preserves_lsblk(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('private diagnostic'));
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo consultar el uso del espacio.')
            ->assertDontSeeText('private diagnostic')->assertSeeText('disk-preserved');
    }

    public function test_html_is_escaped(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->mock(DfRunner::class)->shouldReceive('run')->once()->andReturn(self::HEADER."<script>alert(1)</script> 1G 0 1G 0% /<img src=x onerror=alert(1)>\n");
        $this->get('/almacenamiento')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('/&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_fixed_command_locale_timeout_and_no_parameters(): void
    {
        $runner = new class extends DfRunner
        {
            public function inspect(): Process
            {
                return $this->createProcess();
            }
        };
        $process = $runner->inspect();
        $this->assertSame("'/usr/bin/df' '-hP'", $process->getCommandLine());
        $this->assertSame(['LC_ALL' => 'C'], $process->getEnv());
        $this->assertSame(5.0, $process->getTimeout());
        $this->assertSame(0, (new \ReflectionMethod(DfRunner::class, 'run'))->getNumberOfParameters());
    }
}
