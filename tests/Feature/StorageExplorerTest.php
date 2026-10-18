<?php

namespace Tests\Feature;

use App\Services\Storage\DfRunner;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\SystemUsersReader;
use App\Services\Storage\WhoRunner;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorageExplorerTest extends TestCase
{
    private string $sandbox;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/sysmonitor-explorer-'.bin2hex(random_bytes(8));
        $this->root = $this->sandbox.'/root';
        mkdir($this->root.'/sub/nested', 0700, true);
        mkdir($this->root.'/empty');
        mkdir($this->sandbox.'/root-neighbor');
        file_put_contents($this->root.'/example.txt', 'unchanged');
        file_put_contents($this->sandbox.'/root-neighbor/secret.txt', 'outside secret');
        $this->app->instance(FileExplorerService::class, new FileExplorerService($this->root));
        $this->mock(LsblkRunner::class)->shouldReceive('run')->andReturn('{"blockdevices":[]}');
        $this->mock(DfRunner::class)->shouldReceive('run')->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
        $this->mock(WhoRunner::class)->shouldReceive('run')->andReturn('');
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('passwd')->andReturn('');
        $reader->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
    }

    protected function tearDown(): void
    {
        $remove = function (string $path) use (&$remove): void {
            if (is_link($path) || ! is_dir($path)) {
                unlink($path);

                return;
            }
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $remove($path.'/'.$name);
                }
            }
            rmdir($path);
        };
        $remove($this->sandbox);
        parent::tearDown();
    }

    public function test_root_listing_is_sorted_and_files_cannot_be_opened(): void
    {
        $result = app(FileExplorerService::class)->browse();
        $this->assertTrue($result['available']);
        $this->assertSame('', $result['path']);
        $this->assertNull($result['parent']);
        $this->assertSame(['empty', 'example.txt', 'sub'], array_column($result['entries'], 'name'));
        $this->assertSame(['Directorio', 'Archivo', 'Directorio'], array_column($result['entries'], 'type'));
        $this->assertNull($result['entries'][1]['path']);
        $this->assertFalse(app(FileExplorerService::class)->browse('example.txt')['available']);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('Explorador de archivos')->assertSeeText('example.txt')
            ->assertSeeText('Directorio actual: /')->assertDontSeeText('Directorio padre')
            ->assertDontSeeText($this->sandbox)->assertDontSeeText('unchanged');
    }

    public function test_subdirectory_navigation_and_parent_stay_inside_root(): void
    {
        $service = app(FileExplorerService::class);
        $result = $service->browse('sub/nested');
        $this->assertSame('sub/nested', $result['path']);
        $this->assertSame('sub', $result['parent']);
        $this->assertSame('', $service->browse($result['parent'])['parent']);
        $this->assertNull($service->browse('')['parent']);
        $this->get('/almacenamiento?path=sub')->assertOk()->assertSeeText('Directorio actual: /sub')
            ->assertSee(route('storage.index', ['path' => 'sub/nested']), false)->assertSeeText('Directorio padre');
    }

    public static function unsafePaths(): array
    {
        return [
            ['../root-neighbor'], ['sub/../../root-neighbor'], ['/etc'], ['//etc'], ['C:\\Windows'],
            ['%2e%2e/root-neighbor'], ['%2E%2E%2Froot-neighbor'], ['%252e%252e%252froot-neighbor'],
            ['..\\root-neighbor'], ["sub\0/nested"], ['%00'], [['sub']],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected_by_service_and_http(mixed $path): void
    {
        $this->assertFalse(app(FileExplorerService::class)->browse($path)['available']);
        $this->get('/almacenamiento?'.http_build_query(['path' => $path]))->assertOk()
            ->assertSeeText('No se pudo acceder al directorio solicitado.')
            ->assertDontSeeText('outside secret')->assertDontSeeText($this->sandbox);
    }

    public function test_raw_encoded_traversal_is_rejected_after_http_decoding(): void
    {
        $this->get('/almacenamiento?path=%2e%2e%2froot-neighbor')->assertOk()
            ->assertSeeText('No se pudo acceder al directorio solicitado.');
    }

    public function test_symlink_escape_and_directory_prefix_collision_are_blocked(): void
    {
        symlink($this->sandbox.'/root-neighbor', $this->root.'/escape');
        symlink($this->root.'/sub', $this->root.'/inside');
        symlink($this->sandbox.'/missing', $this->root.'/broken');
        $service = app(FileExplorerService::class);
        $entries = array_column($service->browse()['entries'], null, 'name');
        $this->assertSame('Enlace simbólico', $entries['escape']['type']);
        $this->assertNull($entries['escape']['path']);
        $this->assertNull($entries['broken']['path']);
        $this->assertSame('inside', $entries['inside']['path']);
        $this->assertSame('sub', $service->browse('inside')['path']);
        $this->assertFalse($service->browse('escape')['available']);
        $this->get('/almacenamiento?path=escape')->assertOk()->assertSeeText('No se pudo acceder al directorio solicitado.')
            ->assertDontSeeText('secret.txt')->assertDontSeeText($this->sandbox);
    }

    public function test_missing_empty_and_unreadable_directories_have_controlled_messages(): void
    {
        $this->get('/almacenamiento?path=missing')->assertOk()->assertSeeText('No se pudo acceder al directorio solicitado.');
        $this->get('/almacenamiento?path=empty')->assertOk()->assertSeeText('El directorio está vacío.');
        $service = new class($this->root) extends FileExplorerService
        {
            protected function directoryEntries(string $directory): array
            {
                throw new \RuntimeException('private path');
            }
        };
        $this->app->instance(FileExplorerService::class, $service);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo acceder al directorio solicitado.')
            ->assertDontSeeText('private path');
    }

    public function test_html_names_and_navigation_urls_are_escaped(): void
    {
        file_put_contents($this->root.'/<img onerror=alert(1)>', 'never displayed');
        mkdir($this->root.'/folder"<tag>&');
        $this->get('/almacenamiento')->assertOk()->assertSee('&lt;img onerror=alert(1)&gt;', false)
            ->assertSee('folder&quot;&lt;tag&gt;&amp;', false)->assertDontSee('<img', false)
            ->assertDontSee('<tag>', false)->assertDontSeeText('never displayed');
    }

    public function test_http_cannot_write_delete_upload_or_download_files(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, '/almacenamiento', ['path' => 'example.txt', 'action' => 'delete', 'content' => 'changed'])
                ->assertStatus(405);
        }
        $this->post('/almacenamiento', ['file' => UploadedFile::fake()->createWithContent('uploaded.txt', 'upload')])->assertStatus(405);
        $this->get('/almacenamiento?path=example.txt&action=download')->assertOk()
            ->assertDontSeeText('unchanged')->assertHeaderMissing('Content-Disposition');
        $this->get('/almacenamiento?path=&action=delete&file=example.txt')->assertOk()->assertDontSee('<form', false);
        $this->assertSame('unchanged', file_get_contents($this->root.'/example.txt'));
        $this->assertFileDoesNotExist($this->root.'/uploaded.txt');
        $this->assertDirectoryExists($this->root.'/sub');
        $this->assertSame(['.', '..', 'empty', 'example.txt', 'sub'], scandir($this->root));
    }
}
