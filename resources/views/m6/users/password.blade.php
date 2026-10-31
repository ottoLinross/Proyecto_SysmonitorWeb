@extends('layouts.sysmonitor')

@section('title', 'Cambiar contraseña')

@section('content')
    <h1>Cambiar contraseña</h1>
    <p>Cuenta: {{ $user->name }} ({{ $user->email }})</p>
    @if (session('error')) <p role="alert">{{ session('error') }}</p> @endif
    @if ($errors->any()) <div role="alert"><p>Revisa los datos del formulario.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <form method="POST" action="{{ route('users.password.update', $user) }}">
        @csrf
        @method('PUT')
        <label for="password">Contraseña nueva (mínimo 12 caracteres)</label>
        <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
        <label for="password_confirmation">Confirmar contraseña</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
        <button type="submit">Actualizar contraseña</button>
    </form>
    <p><a href="{{ route('users.edit', $user) }}">Volver a editar usuario</a></p>
@endsection
