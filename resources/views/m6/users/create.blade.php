@extends('layouts.sysmonitor')

@section('title', 'Crear usuario')

@section('content')
    <h1>Crear usuario</h1>
    @if (session('error')) <p role="alert">{{ session('error') }}</p> @endif
    @if ($errors->any()) <div role="alert"><p>Revisa los datos del formulario.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <form method="POST" action="{{ route('users.store') }}">
        @csrf
        <label for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name">
        <label for="email">Correo electrónico</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email">
        <label for="role">Rol</label>
        <select id="role" name="role" required>
            <option value="observer" @selected(old('role') === 'observer')>Observador</option>
            <option value="admin" @selected(old('role') === 'admin')>Administrador</option>
        </select>
        <label for="password">Contraseña nueva (mínimo 12 caracteres)</label>
        <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
        <label for="password_confirmation">Confirmar contraseña</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
        <button type="submit">Crear usuario</button>
    </form>
    <p><a href="{{ route('users.index') }}">Volver a usuarios</a></p>
@endsection
