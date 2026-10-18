<?php

namespace Tests\Feature;

use App\Services\Storage\DfRunner;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\SystemUsersReader;
use App\Services\Storage\SystemUsersService;
use App\Services\Storage\WhoRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorageUsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(WhoRunner::class)->shouldReceive('run')->andReturn('');
        $this->mock(LsblkRunner::class)->shouldReceive('run')->andReturn('{"blockdevices":[]}');
        $this->mock(DfRunner::class)->shouldReceive('run')->andReturn("Filesystem Size Used Avail Use% Mounted on\n");
        $this->mock(FindmntRunner::class)->shouldReceive('run')->andReturn('{"filesystems":[]}');
        $this->mock(FstabReader::class)->shouldReceive('read')->andReturn('');
    }

    public function test_classification_validation_empty_fields_and_deterministic_order(): void
    {
        $rows = [
            'zoe:x:2001:2001::/home/zoe:/bin/bash',
            'boundary::2000:2000:::',
            'root:x:0:0:root:/root:/bin/bash',
            'daemon:x:1999:42::/:/usr/sbin/nologin',
            'amy:x:2001:2001::/home/amy:/bin/sh',
            'invalid', ':x:1:1::/:/bin/sh', 'bad:x:-1:2:::',
            'bad:x:2:no:::', 'bad:x:999999999999999999999:2:::',
            'bad:x::2:::', 'bad:x:1:2::::',
        ];
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('loginDefs')->andReturn("# UID_MIN 50\n UID_MIN 2000 # local policy\n");
        $reader->shouldReceive('passwd')->andReturn(implode("\n", $rows), implode("\n", array_reverse($rows)));
        $result = app(SystemUsersService::class)->users();
        $this->assertSame($result, app(SystemUsersService::class)->users());
        $this->assertSame(['root', 'daemon', 'boundary', 'amy', 'zoe'], array_column($result['users'], 'username'));
        $this->assertSame(['root', 'Usuario del sistema', 'Usuario humano', 'Usuario humano', 'Usuario humano'], array_column($result['users'], 'classification'));
        $this->assertSame('', $result['users'][2]['home']);
        $this->assertSame('', $result['users'][2]['shell']);
        $this->assertSame(42, $result['users'][1]['gid']);
        $this->assertFalse($result['fallback']);
    }

    public static function specialUserClassifications(): array
    {
        return [
            'nobody with reserved UID' => ['nobody', 65534, 'Usuario del sistema'],
            'nobody with another UID' => ['nobody', 2000, 'Usuario del sistema'],
            'reserved UID with another name' => ['overflow', 65534, 'Usuario del sistema'],
            'normal human at UID_MIN' => ['human', 1000, 'Usuario humano'],
            'root name retains priority' => ['root', 65534, 'root'],
            'root UID retains priority' => ['nobody', 0, 'root'],
        ];
    }

    #[DataProvider('specialUserClassifications')]
    public function test_special_accounts_preserve_root_and_normal_human_classification(string $username, int $uid, string $expected): void
    {
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $reader->shouldReceive('passwd')->andReturn("{$username}:x:{$uid}:1000::/home/example:/bin/sh");

        $result = app(SystemUsersService::class)->users();

        $this->assertTrue($result['available']);
        $this->assertFalse($result['fallback']);
        $this->assertCount(1, $result['users']);
        $this->assertSame($expected, $result['users'][0]['classification']);
    }

    public static function invalidDefinitions(): array
    {
        return [[''], ['# UID_MIN 2000'], ['UID_MIN nope'], ['UID_MIN -1'], ['UID_MIN 0'], ['UID_MIN 999999999999999999999'], ['UID_MIN 2000 extra'], [null]];
    }

    #[DataProvider('invalidDefinitions')]
    public function test_uid_min_fallback(?string $definitions): void
    {
        $reader = $this->mock(SystemUsersReader::class);
        $expectation = $reader->shouldReceive('loginDefs');
        if ($definitions === null) {
            $expectation->andThrow(new \RuntimeException('private'));
        } else {
            $expectation->andReturn($definitions);
        }
        $reader->shouldReceive('passwd')->andReturn('human:x:1000:1000::/home/human:/bin/sh');
        $result = app(SystemUsersService::class)->users();
        $this->assertSame(1000, $result['uid_min']);
        $this->assertTrue($result['fallback']);
        $this->assertSame('Usuario humano', $result['users'][0]['classification']);
        $this->get('/almacenamiento')->assertOk()->assertSeeText('valor alternativo 1000')->assertDontSeeText('private');
    }

    public function test_unreadable_passwd_and_empty_results_preserve_sections(): void
    {
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $reader->shouldReceive('passwd')->andThrow(new \RuntimeException('private'));
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se pudo leer /etc/passwd')
            ->assertDontSeeText('private')->assertSeeText('Dispositivos de almacenamiento')
            ->assertSeeText('Uso del espacio')->assertSeeText('Puntos de montaje');
    }

    public function test_empty_passwd(): void
    {
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $reader->shouldReceive('passwd')->andReturn('');
        $this->get('/almacenamiento')->assertOk()->assertSeeText('No se encontraron usuarios válidos');
    }

    public function test_table_escapes_all_text_fields_and_is_read_only(): void
    {
        $reader = $this->mock(SystemUsersReader::class);
        $reader->shouldReceive('loginDefs')->andReturn('UID_MIN 1000');
        $reader->shouldReceive('passwd')->andReturn('<script>user</script>:x:1000:42::<home>:<shell>');
        $this->get('/almacenamiento')->assertOk()->assertSeeText('Nombre de usuario')->assertSeeText('Clasificación')
            ->assertSee('&lt;script&gt;user&lt;/script&gt;', false)->assertSee('&lt;home&gt;', false)
            ->assertSee('&lt;shell&gt;', false)->assertSeeText('Usuario humano')->assertDontSee('<script>', false)
            ->assertDontSee('<form', false)->assertSeeText('solo lectura');
    }
}
