<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Almacenamiento — SysMonitor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        h1 { margin: 0 0 12px; font-size: 1.8rem; }
        h2 { margin: 0 0 16px; font-size: 1.2rem; }
        p { color: #526173; line-height: 1.5; }
        .panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 24px; margin-top: 24px; }
        section { min-width: 0; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        @media (max-width: 800px) { main { padding: 24px 16px; } }
    </style>
</head>
<body>
    <main>
        <header>
            <p>SysMonitor · M5</p>
            <h1>Almacenamiento</h1>
            <p>Estructura inicial del módulo de almacenamiento.</p>
            <p>La consulta de datos del sistema todavía no está implementada.</p>
        </header>
        <div class="panels">
            @foreach ($sections as $id => $title)
                <section aria-labelledby="{{ $id }}-title">
                    <h2 id="{{ $id }}-title">{{ $title }}</h2>
                    <p>Pendiente de implementación. No se han consultado datos.</p>
                </section>
            @endforeach
        </div>
    </main>
</body>
</html>
