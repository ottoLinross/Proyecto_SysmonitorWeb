<?php

namespace Tests\Feature;

use App\Http\Controllers\StorageController;
use App\Http\Middleware\AuthenticateStorage;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\LsblkRunner;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StorageAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['storage_access.username' => 'storage-test', 'storage_access.password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    public function test_anonymous_and_http_parameter_bypass_attempts_never_reach_sources(): void
    {
        $this->mock(StorageController::class)->shouldNotReceive('index');
        $this->mock(LsblkRunner::class)->shouldNotReceive('run');
        $this->mock(FileExplorerService::class)->shouldNotReceive('browse');
        foreach (['', '?username=storage-test&password=test-only-password', '?PHP_AUTH_USER=storage-test&PHP_AUTH_PW=test-only-password', '?Authorization=Basic%20'.base64_encode('storage-test:test-only-password'), '?path[]=.env&authenticated=1', '?path=../&action=download'] as $query) {
            $response = $this->get('/almacenamiento'.$query)->assertUnauthorized()
                ->assertHeader('WWW-Authenticate', 'Basic realm="SysMonitor M5", charset="UTF-8"');
            $this->assertSame('Acceso no autorizado.', $response->getContent());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->head('/almacenamiento')->assertUnauthorized();
    }

    public function test_wrong_user_password_and_malformed_authorization_are_denied(): void
    {
        $this->mock(StorageController::class)->shouldNotReceive('index');
        foreach (['wrong:test-only-password', 'storage-test:wrong', 'storage-test:', ':test-only-password'] as $credentials) {
            $this->get('/almacenamiento', ['Authorization' => 'Basic '.base64_encode($credentials)])->assertUnauthorized();
        }
        foreach (['Bearer token', 'Basic !!!', 'Basic '.base64_encode('missing-colon')] as $header) {
            $this->get('/almacenamiento', ['Authorization' => $header])->assertUnauthorized();
        }
    }

    public function test_valid_credentials_reach_controller(): void
    {
        $this->mock(StorageController::class)->shouldReceive('index')->once()->andReturn(view('welcome'));
        $response = $this->withBasicAuth('storage-test', 'test-only-password')->get('/almacenamiento')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_missing_empty_or_invalid_configuration_fails_closed(): void
    {
        $this->mock(StorageController::class)->shouldNotReceive('index');
        foreach ([[null, null], ['', ''], ['storage-test', null], [null, password_hash('test-only-password', PASSWORD_BCRYPT, ['cost' => 4])], ['storage-test', 'invalid-hash'], [[], []]] as [$user, $hash]) {
            config(['storage_access.username' => $user, 'storage_access.password_hash' => $hash]);
            $this->withBasicAuth('storage-test', 'test-only-password')->get('/almacenamiento')->assertUnauthorized();
        }
    }

    public function test_middleware_is_exclusive_to_storage(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertSame($route->uri() === 'almacenamiento', in_array(AuthenticateStorage::class, $route->gatherMiddleware(), true), $route->uri());
        }
    }
}
