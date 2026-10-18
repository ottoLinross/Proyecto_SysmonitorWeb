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
            ['../root-neighbor'], ['../../'], ['..'], ['/'], ['file:///etc'], ['sub/../../root-neighbor'], ['/etc'], ['//etc'], ['C:\\Windows'],
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

    public function test_file_directory_and_link_metadata_use_the_entry_itself(): void
    {
        chmod($this->root.'/example.txt', 0644);
        chmod($this->root.'/sub', 0755);
        symlink($this->root.'/example.txt', $this->root.'/file-link');
        symlink($this->sandbox.'/root-neighbor/secret.txt', $this->root.'/external-link');
        symlink($this->sandbox.'/missing', $this->root.'/broken-link');
        $entries = array_column(app(FileExplorerService::class)->browse()['entries'], null, 'name');
        $this->assertSame('-rw-r--r--', $entries['example.txt']['permissions']);
        $this->assertSame('drwxr-xr-x', $entries['sub']['permissions']);
        $this->assertSame('9 bytes', $entries['example.txt']['size']);
        $this->assertSame('—', $entries['sub']['size']);
        $uid = lstat($this->root.'/example.txt')['uid'];
        $user = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        $this->assertSame($user['name'] ?? (string) $uid, $entries['example.txt']['owner']);
        foreach (['file-link', 'external-link', 'broken-link'] as $name) {
            $this->assertSame('lrwxrwxrwx', $entries[$name]['permissions']);
            $this->assertSame('Enlace simbólico', $entries[$name]['type']);
            $this->assertSame('—', $entries[$name]['size']);
            $this->assertNull($entries[$name]['path']);
        }
        $this->get('/almacenamiento')->assertOk()->assertSeeText('Permisos Unix')
            ->assertSeeText('Propietario')->assertSeeText('Tamaño')->assertSeeText('-rw-r--r--')
            ->assertDontSeeText($this->sandbox)->assertDontSeeText('outside secret');
    }

    public static function metadataCases(): array
    {
        return [
            [0100644, 0, '-rw-r--r--', '0 bytes'],
            [0100755, 1023, '-rwxr-xr-x', '1023 bytes'],
            [0100600, 1024, '-rw-------', '1.00 KiB'],
            [0100640, 1536, '-rw-r-----', '1.50 KiB'],
            [0100644, 1048576, '-rw-r--r--', '1.00 MiB'],
            [0100644, 1572864, '-rw-r--r--', '1.50 MiB'],
            [0107755, 0, '-rwsr-sr-t', '0 bytes'],
            [0107644, 0, '-rwSr-Sr-T', '0 bytes'],
            [0040755, 4096, 'drwxr-xr-x', '—'],
            [0120777, 80, 'lrwxrwxrwx', '—'],
        ];
    }

    #[DataProvider('metadataCases')]
    public function test_mode_bits_sizes_and_numeric_uid_fallback(int $mode, int $size, string $permissions, string $formattedSize): void
    {
        $service = new class($this->root, $mode, $size) extends FileExplorerService
        {
            public function __construct(string $root, private int $mode, private int $size)
            {
                parent::__construct($root);
            }

            protected function entryStat(string $path): array|false
            {
                return ['mode' => $this->mode, 'uid' => 123456789, 'size' => $this->size];
            }

            protected function lookupOwner(int $uid): array|false
            {
                return false;
            }
        };
        $entry = $service->browse()['entries'][0];
        $this->assertSame($permissions, $entry['permissions']);
        $this->assertSame($formattedSize, $entry['size']);
        $this->assertSame('123456789', $entry['owner']);
    }

    public function test_invalid_account_and_failed_metadata_are_safe_and_owner_is_escaped(): void
    {
        $service = new class($this->root) extends FileExplorerService
        {
            public array|false $user = ['name' => ''];

            public bool $fail = false;

            protected function entryStat(string $path): array|false
            {
                if ($this->fail && basename($path) === 'example.txt') {
                    return false;
                }

                return parent::entryStat($path);
            }

            protected function lookupOwner(int $uid): array|false
            {
                return $this->user;
            }
        };
        $uid = (string) lstat($this->root.'/example.txt')['uid'];
        foreach ([['name' => ''], ['name' => 123], []] as $user) {
            $service->user = $user;
            $entries = array_column($service->browse()['entries'], null, 'name');
            $this->assertSame($uid, $entries['example.txt']['owner']);
        }
        $service->user = ['name' => '<script>owner</script>'];
        $this->app->instance(FileExplorerService::class, $service);
        $this->get('/almacenamiento')->assertOk()->assertSee('&lt;script&gt;owner&lt;/script&gt;', false)
            ->assertDontSee('<script>owner</script>', false);
        $service->fail = true;
        $result = $service->browse();
        $this->assertTrue($result['available']);
        $entries = array_column($result['entries'], null, 'name');
        foreach (['permissions', 'owner', 'size'] as $field) {
            $this->assertSame('—', $entries['example.txt'][$field]);
        }
        $this->assertSame('drwx', substr($entries['sub']['permissions'], 0, 4));
    }

    public function test_symlink_loops_are_not_navigable_and_do_not_leak_paths(): void
    {
        symlink('loop-b', $this->root.'/loop-a');
        symlink('loop-a', $this->root.'/loop-b');
        symlink('.', $this->root.'/self-root');
        $entries = array_column(app(FileExplorerService::class)->browse()['entries'], null, 'name');
        $this->assertNull($entries['loop-a']['path']);
        $this->assertSame('Enlace simbólico', $entries['loop-a']['type']);
        $this->get('/almacenamiento?path=loop-a')->assertOk()
            ->assertSeeText('No se pudo acceder al directorio solicitado.')->assertDontSeeText($this->sandbox);
        $result = app(FileExplorerService::class)->browse('self-root');
        $this->assertTrue($result['available']);
        $this->assertSame('', $result['path']);
        $this->assertNull($result['parent']);
    }

    public function test_unicode_names_and_directory_navigation_work_without_reading_file_contents(): void
    {
        mkdir($this->root.'/Árbol-日本語');
        file_put_contents($this->root.'/Árbol-日本語/📄 résumé.txt', 'private contents');
        $this->get('/almacenamiento')->assertOk()->assertSeeText('Árbol-日本語');
        $this->get('/almacenamiento?'.http_build_query(['path' => 'Árbol-日本語']))->assertOk()
            ->assertSeeText('📄 résumé.txt')->assertDontSeeText('private contents');
    }

    public function test_unreadable_files_are_never_opened_and_inaccessible_directories_fail_safely(): void
    {
        chmod($this->root.'/example.txt', 0000);
        chmod($this->root.'/sub', 0000);
        try {
            $this->get('/almacenamiento')->assertOk()->assertSeeText('example.txt')->assertDontSeeText('unchanged');
            $this->assertFalse(app(FileExplorerService::class)->browse('example.txt')['available']);
            if (is_readable($this->root.'/sub')) {
                $this->markTestSkipped('The current account bypasses Unix read permissions.');
            }
            $this->get('/almacenamiento?path=sub')->assertOk()
                ->assertSeeText('No se pudo acceder al directorio solicitado.')->assertDontSeeText('nested');
        } finally {
            chmod($this->root.'/example.txt', 0600);
            chmod($this->root.'/sub', 0700);
        }
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
