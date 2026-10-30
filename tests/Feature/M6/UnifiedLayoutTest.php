<?php

namespace Tests\Feature\M6;

use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UnifiedLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_home_uses_the_reusable_layout(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');

        $response = $this->get('/');

        $response->assertOk()
            ->assertViewIs('welcome')
            ->assertSee('<div class="sysmonitor-app">', false)
            ->assertSee('<main class="sm-page" id="main-content"', false)
            ->assertSee('<title>Inicio — '.e(config('app.name', 'SysMonitor Web')).'</title>', false)
            ->assertSeeText('Monitoreo del sistema')
            ->assertSeeText('Módulos disponibles');
    }

    public function test_admin_and_observer_are_identified_without_changing_their_navigation(): void
    {
        foreach ([User::ROLE_ADMIN => 'Administrador', User::ROLE_OBSERVER => 'Observador'] as $role => $label) {
            $user = $this->user($role, 'Layout '.$label);
            $response = $this->actingAs($user, 'web')->get('/');

            $response->assertOk()->assertSeeText($label)->assertSeeText('Layout '.$label);
        }
    }

    public function test_unknown_role_is_not_presented_as_a_valid_role_or_granted_admin_access(): void
    {
        $user = $this->user(User::ROLE_OBSERVER, 'Layout Unknown Role');
        $user->setRawAttributes(array_replace($user->getAttributes(), ['role' => 'unexpected-role']));

        $this->mock(TestProcessService::class)->shouldNotReceive('launch');

        $response = $this->actingAs($user, 'web')->get('/');

        $response->assertOk()
            ->assertSeeText('Rol no reconocido')
            ->assertDontSeeText('Administrador')
            ->assertDontSeeText('Observador')
            ->assertDontSeeText('Lanzar proceso de prueba')
            ->assertDontSeeText('Cambiar prioridad')
            ->assertDontSeeText('Enviar señal');

        $this->post(route('processes.test.store'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_before_the_layout_renders(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_module_navigation_uses_the_five_existing_get_routes(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');
        $response = $this->get('/');
        $modules = [
            'processes.index' => ['/procesos', 'Procesos'],
            'cpu-memory.index' => ['/cpu-memoria', 'CPU y Memoria'],
            'scheduling.index' => ['/planificacion', 'Planificación'],
            'deadlocks.index' => ['/interbloqueos', 'Interbloqueos'],
            'storage.index' => ['/almacenamiento', 'Almacenamiento'],
        ];

        foreach ($modules as $routeName => [$path, $label]) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertContains('GET', $route->methods());
            $this->assertSame($path, route($routeName, [], false));
            $response->assertSee('href="'.route($routeName).'"', false)->assertSeeText($label);
        }

        $response->assertSee('href="'.url('/').'"', false)->assertSeeText('Inicio');
    }

    public function test_home_navigation_is_marked_active(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $response = $this->get('/');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//a[@href="'.url('/').'" and @aria-current="page"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('processes.index').'" and @aria-current="page"]')->length);
    }

    public function test_user_name_is_html_escaped(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER, '<script>alert("layout-xss")</script>'), 'web');
        $response = $this->get('/');

        $response->assertOk()->assertSee('&lt;script&gt;alert(&quot;layout-xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("layout-xss")</script>', false);
    }

    public function test_logout_is_a_post_form_with_a_csrf_field(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $response = $this->get('/');

        $response->assertOk()->assertSee('action="'.route('logout').'"', false)->assertSee('method="POST"', false)
            ->assertSee('name="_token"', false)->assertSeeText('Cerrar sesión');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@action="'.route('logout').'" and @method="POST"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(1, $xpath->query('.//input[@type="hidden"][@name="_token"]', $form)->length);
    }

    public function test_page_does_not_print_email_password_or_remember_token(): void
    {
        $user = $this->user(User::ROLE_ADMIN);
        $response = $this->actingAs($user, 'web')->get('/');

        $response->assertOk()->assertDontSee($user->email)->assertDontSee('remember_token')->assertDontSee('password');
    }

    public function test_layout_contains_keyboard_landmarks_and_mobile_navigation(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');
        $response = $this->get('/');

        $response->assertOk()->assertSee('href="#main-content"', false)
            ->assertSee('aria-label="Navegación principal"', false)
            ->assertSee('aria-label="Módulos de SysMonitor"', false)
            ->assertSee('<details class="sm-mobile-menu">', false)
            ->assertSee('<summary aria-label="Abrir navegación de módulos">', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('@media (max-width: 720px)', false);
    }

    public function test_observer_home_has_no_process_operation_controls(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');
        $response = $this->get('/');

        $response->assertOk()->assertDontSeeText('Lanzar proceso de prueba')
            ->assertDontSeeText('Cambiar prioridad')->assertDontSeeText('Enviar señal');
    }

    public function test_layout_keeps_storage_basic_and_admin_route_protection_unchanged(): void
    {
        $storage = Route::getRoutes()->getByName('storage.index');
        $launch = Route::getRoutes()->getByName('processes.test.store');

        $this->assertNotNull($storage);
        $this->assertNotNull($launch);
        $this->assertContains(AuthenticateStorage::class, $storage->gatherMiddleware());
        $this->assertContains(EnsureUserIsAdmin::class, $launch->gatherMiddleware());
        $this->assertCount(28, Route::getRoutes()->getRoutes());
    }

    private function user(string $role, string $name = 'Layout Test User'): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = uniqid('layout-test-', true).'@example.test';
        $user->setRawAttributes(array_replace($user->getAttributes(), ['password' => '']));
        $user->role = $role;
        $user->save();

        return $user;
    }
}
