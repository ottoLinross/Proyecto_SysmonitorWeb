<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión — SysMonitor Web</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; font-family: system-ui, sans-serif; color: #182332; background: #eff6ff; }
        main { width: 100%; max-width: 420px; padding: 32px; border: 1px solid #cbd5e1; border-radius: 12px; background: white; }
        h1 { margin: 0 0 8px; color: #1e40af; font-size: 1.8rem; }
        h2 { margin: 0 0 24px; font-size: 1.1rem; font-weight: 500; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input { width: 100%; min-width: 0; padding: 12px; margin-bottom: 20px; border: 1px solid #94a3b8; border-radius: 6px; font: inherit; }
        button { width: 100%; padding: 12px; border: 0; border-radius: 6px; color: white; background: #1d4ed8; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { background: #1e40af; }
        input:focus-visible, button:focus-visible { outline: 3px solid #60a5fa; outline-offset: 3px; }
        .errors { margin-bottom: 24px; padding: 12px; border: 1px solid #b91c1c; border-radius: 6px; color: #991b1b; }
        .errors ul { margin: 0; padding-left: 20px; }
    </style>
</head>
<body>
<main>
    <h1>SysMonitor Web</h1>
    <h2>Iniciar sesión</h2>
    @if ($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" maxlength="254" required autofocus>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Ingresar</button>
    </form>
</main>
</body>
</html>
