@extends('layouts.sysmonitor')

@section('title', 'Gestión de usuarios')

@section('content')
    <header class="module-header">
        <p>Administración</p>
        <h1>Gestión de usuarios</h1>
        <p>Administra las cuentas y sus roles. Las contraseñas y sus hashes nunca se muestran.</p>
        <p><a href="{{ route('users.create') }}">Crear usuario</a></p>
    </header>

    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @if (session('error')) <p role="alert">{{ session('error') }}</p> @endif

    <div class="sm-table-frame">
        <table>
            <caption>Usuarios registrados</caption>
            <thead><tr><th scope="col">Nombre</th><th scope="col">Correo</th><th scope="col">Rol</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->role === \App\Models\User::ROLE_ADMIN ? 'Administrador' : ($user->role === \App\Models\User::ROLE_OBSERVER ? 'Observador' : 'Rol no reconocido') }}</td>
                        <td><a href="{{ route('users.edit', $user) }}">Editar</a> · <a href="{{ route('users.password.edit', $user) }}">Cambiar contraseña</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4">No hay usuarios registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
@endsection
