<?php

namespace Tests\Unit;

use App\Services\Storage\StorageFileRead;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

class StorageFileReadTest extends TestCase
{
    public function test_empty_file_returns_an_empty_string_without_modifying_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'storage-empty-');
        try {
            $this->assertSame(0, filesize($path));
            $this->assertSame('', StorageFileRead::read($path));
            clearstatcache(true, $path);
            $this->assertFileExists($path);
            $this->assertSame(0, filesize($path));
            $this->assertSame('', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_file_reads_are_bounded_and_do_not_modify_contents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'storage-read-');
        try {
            file_put_contents($path, 'read only');
            $this->assertSame('read only', StorageFileRead::read($path));
            $this->assertSame('read only', file_get_contents($path));
            file_put_contents($path, str_repeat('x', StorageFileRead::MAX_BYTES));
            $this->assertSame(StorageFileRead::MAX_BYTES, strlen(StorageFileRead::read($path)));
            file_put_contents($path, 'x', FILE_APPEND);
            $this->expectException(\RuntimeException::class);
            StorageFileRead::read($path);
        } finally {
            unlink($path);
        }
    }

    #[WithoutErrorHandler]
    public function test_missing_file_is_a_controlled_failure_and_is_not_created(): void
    {
        $path = sys_get_temp_dir().'/storage-missing-'.bin2hex(random_bytes(8));
        try {
            StorageFileRead::read($path);
            $this->fail('Missing file must fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Storage source unavailable', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($path);
    }

    #[WithoutErrorHandler]
    public function test_unreadable_file_is_a_controlled_failure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'storage-denied-');
        chmod($path, 0000);
        try {
            if (is_readable($path)) {
                $this->markTestSkipped('The current account bypasses Unix read permissions.');
            }
            $this->expectException(\RuntimeException::class);
            StorageFileRead::read($path);
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }
}
