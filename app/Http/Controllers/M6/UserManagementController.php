<?php

namespace App\Http\Controllers\M6;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\M6\AuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class UserManagementController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(): View
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        return view('m6.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('m6.users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_OBSERVER])],
        ]);

        return $this->audited('user.create', null, function () use ($data): User {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->role = $data['role'];
            $user->save();

            return $user;
        }, 'created', 'users.index', 'Usuario creado.');
    }

    public function edit(User $user): View
    {
        return view('m6.users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->getKey())],
        ]);

        return $this->audited('user.update', $user, function () use ($data, $user): User {
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->save();

            return $user;
        }, 'updated', 'users.edit', 'Datos de usuario actualizados.', $user);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_OBSERVER])],
        ]);

        return $this->audited('user.role', $user, function () use ($data, $user): User {
            DB::transaction(function () use ($data, $user): void {
                $connection = DB::connection();
                if ($connection->getDriverName() === 'sqlite') {
                    // SQLite has no SELECT FOR UPDATE; this no-op write obtains its writer lock before counting admins.
                    DB::table('users')->where('id', $user->getKey())->update(['role' => DB::raw('role')]);
                }

                $lockedUsers = User::query()->orderBy('id')->lockForUpdate()->get(['id', 'role']);
                $target = $lockedUsers->firstWhere('id', $user->getKey());
                $adminCount = $lockedUsers->where('role', User::ROLE_ADMIN)->count();

                if ($target?->role === User::ROLE_ADMIN && $data['role'] !== User::ROLE_ADMIN && $adminCount <= 1) {
                    throw new LastAdministratorException;
                }

                $user->role = $data['role'];
                $user->save();
            });

            return $user->refresh();
        }, 'role_changed', 'users.edit', 'Rol actualizado.', $user);
    }

    public function editPassword(User $user): View
    {
        return view('m6.users.password', compact('user'));
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        return $this->audited('user.password', $user, function () use ($data, $user): User {
            $user->password = $data['password'];
            $user->save();

            return $user;
        }, 'password_changed', 'users.edit', 'Contraseña actualizada.', $user);
    }

    private function audited(
        string $action,
        ?User $target,
        Closure $operation,
        string $successResult,
        string $redirectRoute,
        string $successMessage,
        ?User $redirectUser = null,
    ): RedirectResponse {
        try {
            $audit = $this->auditLogger->begin($action, 'user', $target?->getKey());
        } catch (Throwable) {
            return back()->with('error', 'No se pudo registrar la operación en la bitácora.');
        }

        try {
            $result = $operation();
        } catch (LastAdministratorException) {
            $this->finishFailure($audit);

            return back()->with('error', 'Debe permanecer al menos un Administrador activo.');
        } catch (Throwable) {
            $this->finishFailure($audit);

            return back()->with('error', 'No se pudo completar la operación de usuario.');
        }

        try {
            $this->auditLogger->finish($audit, AuditLog::STATUS_SUCCESS, $successResult, (string) $result->getKey());
        } catch (Throwable) {
            return redirect()->route($redirectRoute, $redirectUser)->with('error', 'No se pudo confirmar el resultado en la bitácora.');
        }

        return redirect()->route($redirectRoute, $redirectUser)->with('status', $successMessage);
    }

    private function finishFailure(AuditLog $audit): void
    {
        try {
            $this->auditLogger->finish($audit, AuditLog::STATUS_FAILURE, 'operation_failed');
        } catch (Throwable) {
            // The pending audit row remains available for investigation; the user operation is never retried.
        }
    }
}

class LastAdministratorException extends \RuntimeException {}
