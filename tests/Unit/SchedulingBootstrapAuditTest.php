<?php

namespace Tests\Unit;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SchedulingBootstrapAuditTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_bootstrap_configuration_never_modifies_a_file(string $environment, bool $fileDatabase, bool $urlOverride): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scheduling-url-guard-');
        $this->assertNotFalse($path);
        $before = hash_file('sha256', $path);
        $url = $urlOverride ? 'sqlite:////'.ltrim($path, '/') : '';
        foreach (['APP_ENV' => $environment, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $fileDatabase ? $path : ':memory:', 'DB_URL' => $url] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $rejected = false;
        try {
            require dirname(__DIR__).'/Support/SchedulingSqliteBootstrap.php';
        } catch (RuntimeException $exception) {
            $rejected = true;
            $this->assertStringContainsString('SQLite', $exception->getMessage());
        } finally {
            HandleExceptions::flushState();
            $after = hash_file('sha256', $path);
            unlink($path);
            $this->assertSame($before, $after, 'A rejected bootstrap must never modify the SQLite file.');
        }

        $this->assertTrue($rejected, 'The effective connection, including DB_URL, must be checked before migrating.');
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'DB_URL overrides memory with a file' => ['testing', false, true],
            'explicit SQLite file' => ['testing', true, false],
            'non-testing environment' => ['local', false, false],
        ];
    }
}
