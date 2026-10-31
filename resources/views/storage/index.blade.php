@extends('layouts.sysmonitor')

@section('page-title', 'Almacenamiento — SysMonitor')
@section('show-logout', 'no')

@section('styles')
    <style>
        #storage-module { max-width: 1440px; margin: 0 auto; padding: 32px 20px; color: #182332; font-family: system-ui, sans-serif; }
        #storage-module h1 { margin: 0 0 12px; font-size: 1.8rem; }
        #storage-module h2 { margin: 0 0 16px; font-size: 1.2rem; }
        #storage-module p { color: #526173; line-height: 1.5; }
        #storage-module .panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 24px; margin-top: 24px; }
        #storage-module section { min-width: 0; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        #storage-module section[aria-labelledby="explorer-title"], #storage-module section[aria-labelledby="sessions-title"], #storage-module section[aria-labelledby="usage-title"], #storage-module section[aria-labelledby="users-title"], #storage-module section[aria-labelledby="mounts-title"] { grid-column: 1 / -1; }
        #storage-module .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 16px; }
        #storage-module .summary-grid div { padding: 12px; background: #f3f5f8; border-radius: 6px; }
        #storage-module .summary-grid dt { font-weight: 600; }
        #storage-module .summary-grid dd { margin: 8px 0 0; overflow-wrap: anywhere; }
        #storage-module .table-scroll { overflow-x: auto; }
        #storage-module table { width: 100%; border-collapse: collapse; }
        #storage-module th, #storage-module td { padding: 8px; text-align: left; border-bottom: 1px solid #cbd5e1; white-space: nowrap; }
        #storage-module #usage-title { margin-bottom: 16px; }
        #storage-module .usage-bar { width: 10rem; height: 0.75rem; margin-top: 6px; overflow: hidden; border: 1px solid #526173; border-radius: 4px; background: #e2e8f0; }
        #storage-module .usage-bar-fill { height: 100%; background: #1d4ed8; }
        @media (max-width: 800px) { #storage-module { padding: 24px 16px; } }
    </style>
@endsection

@section('content')
    <main id="storage-module">

        <header>
        <p>SysMonitor · M5</p>
        <h1>Almacenamiento</h1>
        <p>Discos y particiones del servidor Debian.</p>
        <p>Consulta de solo lectura mediante lsblk, df, findmnt, who, /etc/fstab, /etc/passwd y /etc/login.defs.</p>
        <p><a href="{{ route('storage.index', ['path' => $explorer['available'] ? $explorer['path'] : '']) }}">Actualizar información</a></p>
        </header>
        @include('storage.partials.summary')
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
                @elseif ($id === 'explorer')
                    @include('storage.partials.explorer')
                @elseif ($id === 'sessions')
                    @include('storage.partials.sessions')
                @elseif ($id === 'users')
                    @include('storage.partials.users')
                @elseif ($id === 'mounts')
                    @include('storage.partials.mounts')
                @else
                    <p>Pendiente de implementación. No se han consultado datos.</p>
                @endif
            </section>
        @endforeach
        </div>
    </main>
@endsection
