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
        section[aria-labelledby="usage-title"], section[aria-labelledby="mounts-title"] { grid-column: 1 / -1; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #cbd5e1; white-space: nowrap; }
        #usage-title { margin-bottom: 16px; }
        .usage-bar { width: 10rem; height: 0.75rem; margin-top: 6px; overflow: hidden; border: 1px solid #526173; border-radius: 4px; background: #e2e8f0; }
        .usage-bar-fill { height: 100%; background: #1d4ed8; }
        @media (max-width: 800px) { main { padding: 24px 16px; } }
    </style>
</head>
<body>
    <main>
        <header>
            <p>SysMonitor · M5</p>
            <h1>Almacenamiento</h1>
            <p>Discos y particiones del servidor Debian.</p>
            <p>Consulta de solo lectura mediante lsblk, df, findmnt y /etc/fstab.</p>
        </header>
        <div class="panels">
            @foreach ($sections as $id => $title)
                <section aria-labelledby="{{ $id }}-title">
                    <h2 id="{{ $id }}-title">{{ $title }}</h2>
                    @if ($id === 'devices')
                        @if (! $storage['available'])
                            <p role="status">No se pudo consultar la información de almacenamiento.</p>
                        @elseif ($storage['devices'] === [])
                            <p>No se encontraron dispositivos.</p>
                        @else
                            <ul>
                                @foreach ($storage['devices'] as $device)
                                    @include('storage.partials.device', ['device' => $device])
                                @endforeach
                            </ul>
                        @endif
                    @elseif ($id === 'usage')
                        @include('storage.partials.usage')
                    @elseif ($id === 'mounts')
                        @include('storage.partials.mounts')
                    @else
                        <p>Pendiente de implementación. No se han consultado datos.</p>
                    @endif
                </section>
            @endforeach
        </div>
    </main>
</body>
</html>
