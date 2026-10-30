<?php

namespace Tests\Feature\M6;

use App\Console\Commands\CreateInitialAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class InitialAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'Initial-'.bin2hex(random_bytes(16)).'Aa1!';
    }

    public function test_creates_first_administrator_with_the_model_role_and_a_secure_password_hash(): void
    {
        $this->assertDatabaseCount('users', 0);

        $this->command()
            ->expectsQuestion('Nombre del Administrador', 'Inicial Otto')
            ->expectsQuestion('Correo electrónico', 'initial@example.test')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $this->password)
            ->expectsQuestion('Confirma la contraseña', $this->password)
            ->expectsOutput('Usuario Administrador inicializado correctamente.')
            ->assertExitCode(0);

        $user = User::query()->sole();
        $this->assertTrue($user->isAdmin());
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue(Hash::check($this->password, $user->password));
        $this->assertNotSame($this->password, $user->password);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_email_is_rejected_without_exposing_password_or_email(): void
    {
        $badEmail = 'not-an-email-secret';
        $this->command()
            ->expectsQuestion('Nombre del Administrador', 'Invalid Email')
            ->expectsQuestion('Correo electrónico', $badEmail)
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $this->password)
            ->expectsQuestion('Confirma la contraseña', $this->password)
            ->expectsOutput('The email field must be a valid email address.')
            ->doesntExpectOutput($this->password)
            ->doesntExpectOutput($badEmail)
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_email_is_rejected_and_existing_user_is_unchanged(): void
    {
        $observer = $this->observer('already-used@example.test');
        $originalHash = $observer->password;

        $this->command()
            ->expectsQuestion('Nombre del Administrador', 'Duplicate Email')
            ->expectsQuestion('Correo electrónico', $observer->email)
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $this->password)
            ->expectsQuestion('Confirma la contraseña', $this->password)
            ->expectsOutput('The email has already been taken.')
            ->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(User::ROLE_OBSERVER, $observer->fresh()->role);
        $this->assertSame($originalHash, $observer->fresh()->password);
    }

    public function test_short_and_unconfirmed_passwords_are_rejected_without_being_echoed(): void
    {
        $short = 'Short-1!';
        $shortResult = $this->command()
            ->expectsQuestion('Nombre del Administrador', 'Short Password')
            ->expectsQuestion('Correo electrónico', 'short@example.test')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $short)
            ->expectsQuestion('Confirma la contraseña', $short)
            ->doesntExpectOutput($short)
            ->assertExitCode(1);
        unset($shortResult);

        $mismatch = 'Mismatch-'.bin2hex(random_bytes(8)).'Aa1!';
        $mismatchResult = $this->command()
            ->expectsQuestion('Nombre del Administrador', 'Unconfirmed Password')
            ->expectsQuestion('Correo electrónico', 'mismatch@example.test')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $this->password)
            ->expectsQuestion('Confirma la contraseña', $mismatch)
            ->doesntExpectOutput($this->password)
            ->doesntExpectOutput($mismatch)
            ->assertExitCode(1);
        unset($mismatchResult);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_second_initialization_is_rejected_without_changing_existing_accounts(): void
    {
        $observer = $this->observer('observer@example.test');
        $firstAdmin = $this->admin('first-admin@example.test');
        $originalObserver = $observer->fresh()->getAttributes();
        $originalAdmin = $firstAdmin->fresh()->getAttributes();

        $this->command()
            ->expectsQuestion('Nombre del Administrador', 'Second Admin')
            ->expectsQuestion('Correo electrónico', 'second-admin@example.test')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $this->password)
            ->expectsQuestion('Confirma la contraseña', $this->password)
            ->expectsOutput('Ya existe una cuenta Administrador; no se realizaron cambios.')
            ->assertExitCode(1);

        $this->assertSame(2, User::query()->count());
        $this->assertSame($originalObserver, $observer->fresh()->getAttributes());
        $this->assertSame($originalAdmin, $firstAdmin->fresh()->getAttributes());
        $this->assertSame(1, User::query()->where('role', User::ROLE_ADMIN)->count());
    }

    public function test_command_has_no_password_argument_or_option_and_uses_only_memory_sqlite(): void
    {
        $definition = app(CreateInitialAdmin::class)->getDefinition();

        $this->assertFalse($definition->hasArgument('password'));
        $this->assertFalse($definition->hasOption('password'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(36, Route::getRoutes()->count());
    }

    private function command(): PendingCommand
    {
        return $this->artisan('sysmonitor:create-admin');
    }

    private function observer(string $email): User
    {
        $user = new User;
        $user->name = 'Existing Observer';
        $user->email = $email;
        $user->password = 'Observer-'.bin2hex(random_bytes(12)).'Aa1!';
        $user->role = User::ROLE_OBSERVER;
        $user->save();

        return $user;
    }

    private function admin(string $email): User
    {
        $user = new User;
        $user->name = 'Existing Admin';
        $user->email = $email;
        $user->password = 'Admin-'.bin2hex(random_bytes(12)).'Aa1!';
        $user->role = User::ROLE_ADMIN;
        $user->save();

        return $user;
    }
}
