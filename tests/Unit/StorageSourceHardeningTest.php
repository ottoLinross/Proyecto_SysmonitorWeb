<?php

namespace Tests\Unit;

use App\Services\Storage\ActiveSessionsService;
use App\Services\Storage\FindmntRunner;
use App\Services\Storage\FstabReader;
use App\Services\Storage\LsblkRunner;
use App\Services\Storage\MountService;
use App\Services\Storage\StorageOverviewService;
use App\Services\Storage\SystemUsersReader;
use App\Services\Storage\SystemUsersService;
use App\Services\Storage\WhoRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorageSourceHardeningTest extends TestCase
{
    public function test_malformed_text_rows_are_ignored_without_discarding_valid_unicode(): void
    {
        $reader = $this->createMock(SystemUsersReader::class);
        $reader->method('loginDefs')->willReturn('UID_MIN 1000');
        $reader->method('passwd')->willReturn("bad\0name:x:1:1::/:/bin/sh\ninvalid\xFF:x:2:2::/:/bin/sh\nálvaro:x:1000:1000::/home/álvaro:/bin/sh\nmalformed");
        $users = (new SystemUsersService($reader))->users();
        $this->assertTrue($users['available']);
        $this->assertSame(['álvaro'], array_column($users['users'], 'username'));
        $fstab = $this->createMock(FstabReader::class);
        $fstab->method('read')->willReturn("bad\0 / ext4 defaults\nbad\xFF / ext4 defaults\n/dev/test /media/á ext4 ro 0 1\ninvalid\nx / ext4 ro no 1\n");
        $mounts = (new MountService($this->createMock(FindmntRunner::class), $fstab))->configured();
        $this->assertTrue($mounts['available']);
        $this->assertSame(['/media/á'], array_column($mounts['mounts'], 'target'));
        $runner = $this->createMock(WhoRunner::class);
        $runner->method('run')->willReturn("bad\0 pts/0 2026-10-18 10:00\nbad\xFF pts/1 2026-10-18 10:00\nálvaro pts/2 2026-10-18 10:00 (local)\n");
        $sessions = (new ActiveSessionsService($runner))->sessions();
        $this->assertTrue($sessions['available']);
        $this->assertSame(['álvaro'], array_column($sessions['sessions'], 'username'));
    }

    public static function invalidJson(): array
    {
        return [["\xFF"], [str_repeat('[', 70).str_repeat(']', 70)], ['null'], ['[]'], ['{"blockdevices":[null],"filesystems":[null]}']];
    }

    #[DataProvider('invalidJson')]
    public function test_invalid_json_is_a_controlled_read_error(string $output): void
    {
        $lsblk = $this->createMock(LsblkRunner::class);
        $lsblk->method('run')->willReturn($output);
        $findmnt = $this->createMock(FindmntRunner::class);
        $findmnt->method('run')->willReturn($output);
        foreach ([(new StorageOverviewService($lsblk))->devices(), (new MountService($findmnt, $this->createMock(FstabReader::class)))->active()] as $result) {
            $this->assertFalse($result['available']);
            $this->assertSame('Error de lectura', $result['status']);
        }
    }
}
