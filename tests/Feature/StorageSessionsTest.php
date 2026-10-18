<?php

namespace Tests\Feature;

use App\Services\Storage\ActiveSessionsService;
use App\Services\Storage\DfRunner;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\SystemUsersReader;
use App\Services\Storage\WhoRunner;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StorageSessionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LsblkRunner::class)->shouldReceive('run')->andReturn('{"blockdevices":[]}');
        $this->mock(DfRunner::class)->shouldReceive('run')->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('passwd')->andReturn('root:x:0:0::/root:/bin/bash');
        $reader->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
    }

    public static function sessionOutputs(): array
    {
        $local = ['username' => 'ana', 'terminal' => 'tty1', 'login_at' => '2026-10-18 08:30', 'origin' => null];
        $remote = ['username' => 'luis', 'terminal' => 'pts/0', 'login_at' => '2026-10-18 09:15', 'origin' => '192.0.2.1'];

        return [
            'empty' => ['', []],
            'whitespace' => [" \n\t\n", []],
            'local' => ["ana tty1 2026-10-18 08:30\n", [$local]],
            'remote' => ["luis pts/0 2026-10-18 09:15 (192.0.2.1)\n", [$remote]],
            'multiple' => ["ana tty1 2026-10-18 08:30\nluis pts/0 2026-10-18 09:15 (192.0.2.1)\n", [$local, $remote]],
            'unexpected and incomplete' => ["unexpected\nuser pts/0\nuser tty1 2026-02-30 10:00\nuser tty1 2026-10-18 29:00\nuser tty1 2026-10-18 08:30 extra\nuser tty1 2026-10-18 08:30 (unfinished\nana tty1 2026-10-18 08:30\n", [$local]],
            'only malformed' => ["unexpected\nuser pts/0\n", []],
        ];
    }

    #[DataProvider('sessionOutputs')]
    public function test_parses_mocked_output_and_renders_sessions(string $output, array $expected): void
    {
        $this->mock(WhoRunner::class)->shouldReceive('run')->twice()->andReturn($output);
        $this->assertSame(['available' => true, 'sessions' => $expected], app(ActiveSessionsService::class)->sessions());
        $response = $this->get('/almacenamiento')->assertOk()->assertSeeText('Sesiones activas');
        if ($expected === []) {
            $response->assertSeeText('No hay sesiones activas reportadas.')->assertDontSeeText('No se pudieron consultar las sesiones activas.');
        } else {
            $response->assertSeeText('Host u origen')->assertDontSeeText('No hay sesiones activas reportadas.');
            foreach ($expected as $session) {
                foreach ($session as $value) {
                    $response->assertSeeText($value ?? 'No reportado');
                }
            }
        }
    }

    public function test_command_failure_is_unavailable_and_preserves_other_sections(): void
    {
        $this->mock(WhoRunner::class)->shouldReceive('run')->once()->andThrow(new \RuntimeException('internal secret'));
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudieron consultar las sesiones activas.')
            ->assertDontSeeText('internal secret')->assertDontSeeText('No hay sesiones activas reportadas.')
            ->assertSeeText('Dispositivos de almacenamiento')->assertSeeText('Uso del espacio')
            ->assertSeeText('Puntos de montaje')->assertSeeText('Usuarios del sistema')->assertSeeText('/root');
    }

    public function test_html_is_escaped_and_http_parameters_cannot_trigger_writes(): void
    {
        DB::shouldReceive('connection')->never();
        $this->mock(WhoRunner::class)->shouldReceive('run')->once()
            ->andReturn('<script>user</script> <terminal> 2026-10-18 08:30 (<img src=x onerror=alert(1)>)');
        $this->get('/almacenamiento?command=touch&user=evil&action=logout')->assertOk()
            ->assertSee('&lt;script&gt;user&lt;/script&gt;', false)->assertSee('&lt;terminal&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<form', false);
        $this->post('/almacenamiento', ['action' => 'logout'])->assertStatus(405);
    }

    public function test_runner_uses_only_fixed_read_only_command_and_timeout(): void
    {
        $runner = new class extends WhoRunner
        {
            public function inspect(): Process
            {
                return $this->createProcess();
            }
        };
        $process = $runner->inspect();
        $this->assertSame("'/usr/bin/who'", $process->getCommandLine());
        $this->assertSame(['LC_ALL' => 'C'], $process->getEnv());
        $this->assertSame(5.0, $process->getTimeout());
        $this->assertNull($process->getInput());
        $this->assertSame(0, (new \ReflectionMethod(WhoRunner::class, 'run'))->getNumberOfParameters());
    }
}
