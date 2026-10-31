@extends('layouts.sysmonitor')

@section('title', 'Editar usuario')

@section('content')
    <h1>Editar usuario</h1>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @if (session('error')) <p role="alert">{{ session('error') }}</p> @endif
    @if ($errors->any()) <div role="alert"><p>Revisa los datos del formulario.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf
        @method('PATCH')
        <label for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
        <label for="email">Correo electrónico</label>
        <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="254" autocomplete="email">
        <button type="submit">Guardar datos</button>
    </form>

    <section aria-labelledby="role-title">
        <h2 id="role-title">Cambiar rol</h2>
        <p>El sistema siempre conserva al menos un Administrador.</p>
        <form method="POST" action="{{ route('users.role', $user) }}">
            @csrf
            @method('PATCH')
            <label for="role">Rol</label>
            <select id="role" name="role" required>
                <option value="observer" @selected($user->role === \App\Models\User::ROLE_OBSERVER)>Observador</option>
                <option value="admin" @selected($user->role === \App\Models\User::ROLE_ADMIN)>Administrador</option>
            </select>
            <button type="submit">Actualizar rol</button>
        </form>
    </section>
    <p><a href="{{ route('users.password.edit', $user) }}">Cambiar contraseña</a></p>
    <p><a href="{{ route('users.index') }}">Volver a usuarios</a></p>
@endsection
