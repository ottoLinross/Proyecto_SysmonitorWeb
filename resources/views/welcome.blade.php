@extends('layouts.sysmonitor')

@section('title', 'Inicio')

@section('content')
    @php($metrics = $metrics ?? [
        'cpu' => ['usage_percent' => null, 'model' => null, 'logical_processors' => null, 'load_average' => null, 'uptime' => null],
        'memory' => ['used_bytes' => null, 'total_bytes' => null, 'usage_percent' => null],
        'processes' => ['count' => null],
        'storage' => ['available' => null],
    ])

    <section class="welcome-panel" aria-labelledby="welcome-title">
        <div class="welcome-eyebrow"><span aria-hidden="true"></span> Plataforma operativa</div>
        <h1 id="welcome-title">Monitoreo del sistema</h1>
        <p class="welcome-description">
            Dashboard de lectura de SysMonitor Web. Consulta indicadores disponibles y abre los módulos para explorar sus funciones.
        </p>
        <div class="welcome-note">
            <span class="welcome-note-mark" aria-hidden="true">i</span>
            <p>Usa la navegación lateral para abrir cualquiera de los cinco módulos disponibles.</p>
        </div>
    </section>

    <section class="dashboard-metrics" aria-labelledby="dashboard-metrics-title">
        <div class="welcome-section-heading">
            <div>
                <p class="welcome-kicker">Estado actual</p>
                <h2 id="dashboard-metrics-title">Indicadores principales</h2>
            </div>
            <span class="welcome-module-count">Solo lectura</span>
        </div>
        <div class="dashboard-grid">
            <article class="dashboard-card" aria-labelledby="dashboard-cpu-title">
                <p class="dashboard-card-label">CPU</p>
                <h3 id="dashboard-cpu-title">
                    @if ($metrics['cpu']['usage_percent'] !== null)
                        {{ number_format($metrics['cpu']['usage_percent'], 1, '.', '') }}%
                    @else
                        Dato no disponible
                    @endif
                </h3>
                <p>Utilización</p>
                @if ($metrics['cpu']['model'] !== null)
                    <p class="dashboard-context">{{ $metrics['cpu']['model'] }}</p>
                @endif
                @if ($metrics['cpu']['logical_processors'] !== null)
                    <p class="dashboard-context">{{ $metrics['cpu']['logical_processors'] }} procesadores lógicos</p>
                @endif
                @if ($metrics['cpu']['load_average'] !== null)
                    <p class="dashboard-context">Carga (1 / 5 / 15 min): {{ number_format($metrics['cpu']['load_average']['one_minute'], 2) }} / {{ number_format($metrics['cpu']['load_average']['five_minutes'], 2) }} / {{ number_format($metrics['cpu']['load_average']['fifteen_minutes'], 2) }}</p>
                @endif
                @if ($metrics['cpu']['uptime'] !== null)
                    <p class="dashboard-context">Tiempo activo: {{ $metrics['cpu']['uptime'] }}</p>
                @endif
            </article>

            <article class="dashboard-card" aria-labelledby="dashboard-memory-title">
                <p class="dashboard-card-label">Memoria</p>
                <h3 id="dashboard-memory-title">
                    @if ($metrics['memory']['usage_percent'] !== null)
                        {{ number_format($metrics['memory']['usage_percent'], 1, '.', '') }}%
                    @else
                        Dato no disponible
                    @endif
                </h3>
                @if ($metrics['memory']['usage_percent'] !== null)
                    <p>{{ number_format($metrics['memory']['used_bytes'] / 1073741824, 2, '.', '') }} GiB utilizados de {{ number_format($metrics['memory']['total_bytes'] / 1073741824, 2, '.', '') }} GiB</p>
                @else
                    <p>Uso y capacidad no disponibles.</p>
                @endif
            </article>

            <article class="dashboard-card" aria-labelledby="dashboard-processes-title">
                <p class="dashboard-card-label">Procesos</p>
                <h3 id="dashboard-processes-title">
                    @if ($metrics['processes']['count'] !== null)
                        {{ number_format($metrics['processes']['count']) }}
                    @else
                        Dato no disponible
                    @endif
                </h3>
                <p>Procesos detectados</p>
            </article>

            <article class="dashboard-card" aria-labelledby="dashboard-storage-title">
                <p class="dashboard-card-label">Almacenamiento</p>
                <h3 id="dashboard-storage-title">Dato no disponible</h3>
                <p>La consulta M5 conserva su autenticación HTTP Basic independiente.</p>
            </article>
        </div>
    </section>

    <section class="welcome-modules" aria-labelledby="modules-title">
        <div class="welcome-section-heading">
            <div>
                <p class="welcome-kicker">Espacios de trabajo</p>
                <h2 id="modules-title">Módulos disponibles</h2>
            </div>
            <span class="welcome-module-count">5 módulos</span>
        </div>
        <div class="welcome-grid">
            <a class="welcome-card" href="{{ route('processes.index') }}">
                <span class="welcome-card-code">M1</span><span class="welcome-card-arrow" aria-hidden="true">↗</span>
                <h3>Procesos</h3><p>Consulta procesos y herramientas administradas.</p>
            </a>
            <a class="welcome-card" href="{{ route('cpu-memory.index') }}">
                <span class="welcome-card-code">M2</span><span class="welcome-card-arrow" aria-hidden="true">↗</span>
                <h3>CPU y Memoria</h3><p>Revisa las métricas disponibles del equipo.</p>
            </a>
            <a class="welcome-card" href="{{ route('scheduling.index') }}">
                <span class="welcome-card-code">M3</span><span class="welcome-card-arrow" aria-hidden="true">↗</span>
                <h3>Planificación</h3><p>Explora simulaciones y algoritmos de planificación.</p>
            </a>
            <a class="welcome-card" href="{{ route('deadlocks.index') }}">
                <span class="welcome-card-code">M4</span><span class="welcome-card-arrow" aria-hidden="true">↗</span>
                <h3>Interbloqueos</h3><p>Analiza escenarios de asignación y recuperación.</p>
            </a>
            <a class="welcome-card" href="{{ route('storage.index') }}">
                <span class="welcome-card-code">M5</span><span class="welcome-card-arrow" aria-hidden="true">↗</span>
                <h3>Almacenamiento</h3><p>Consulta volúmenes, uso y sesiones de almacenamiento.</p>
            </a>
        </div>
    </section>
@endsection

@section('styles')
    <style>
        .welcome-panel { position: relative; overflow: hidden; padding: clamp(26px, 5vw, 48px); border: 1px solid #dce7f3; border-radius: 20px; background: linear-gradient(115deg, #fff 4%, #f7fbff 72%, #edf5ff 100%); box-shadow: 0 12px 32px rgba(29, 48, 79, .06); }
        .welcome-panel::after { position: absolute; top: -110px; right: -60px; width: 300px; height: 300px; border: 1px solid rgba(40,120,208,.12); border-radius: 50%; box-shadow: 0 0 0 35px rgba(40,120,208,.035), 0 0 0 75px rgba(40,120,208,.025); content: ""; pointer-events: none; }
        .welcome-eyebrow { display: inline-flex; align-items: center; gap: 8px; color: #28765f; font-size: .73rem; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; }
        .welcome-eyebrow span { width: 7px; height: 7px; border-radius: 50%; background: #28a47d; box-shadow: 0 0 0 4px #e2f5ed; }
        .welcome-panel h1 { position: relative; z-index: 1; margin: 17px 0 12px; color: #182b49; font-size: clamp(1.8rem, 4vw, 2.7rem); font-weight: 760; letter-spacing: -.045em; line-height: 1.12; }
        .welcome-description { position: relative; z-index: 1; max-width: 690px; margin: 0; color: #596981; font-size: 1rem; line-height: 1.75; }
        .welcome-note { position: relative; z-index: 1; display: flex; max-width: 610px; align-items: center; gap: 12px; margin-top: 27px; padding: 13px 15px; border: 1px solid #e0eaf5; border-radius: 11px; background: rgba(255,255,255,.8); }
        .welcome-note-mark { display: grid; width: 24px; height: 24px; flex: 0 0 24px; place-items: center; border-radius: 50%; color: #2671ba; background: #e8f2ff; font-family: Georgia, serif; font-size: .95rem; font-weight: 700; }
        .welcome-note p { margin: 0; color: #53657e; font-size: .84rem; line-height: 1.5; }
        .welcome-modules { margin-top: clamp(30px, 5vw, 46px); }
        .welcome-section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 17px; }
        .welcome-kicker { margin: 0 0 5px; color: #718099; font-size: .68rem; font-weight: 750; letter-spacing: .12em; text-transform: uppercase; }
        .welcome-section-heading h2 { margin: 0; color: #233651; font-size: 1.22rem; font-weight: 720; letter-spacing: -.025em; }
        .welcome-module-count { padding: 6px 10px; border: 1px solid #dfe7f0; border-radius: 999px; color: #65748b; background: #fff; font-size: .7rem; font-weight: 650; white-space: nowrap; }
        .welcome-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 14px; }
        .welcome-card { position: relative; min-height: 160px; padding: 19px; border: 1px solid #e1e8f0; border-radius: 14px; color: inherit; background: #fff; box-shadow: 0 5px 15px rgba(32,51,80,.035); text-decoration: none; transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease; }
        .welcome-card:hover { transform: translateY(-2px); border-color: #b8d3f0; box-shadow: 0 11px 22px rgba(32,71,118,.09); }
        .welcome-card-code { display: inline-flex; min-width: 35px; height: 27px; align-items: center; justify-content: center; border: 1px solid #d8e8f8; border-radius: 7px; color: #276fb5; background: #f1f7ff; font-size: .68rem; font-weight: 800; letter-spacing: .04em; }
        .welcome-card-arrow { position: absolute; top: 19px; right: 19px; color: #93a2b6; font-size: 1rem; }
        .welcome-card h3 { margin: 17px 0 6px; color: #263a56; font-size: .95rem; font-weight: 700; }
        .welcome-card p { max-width: 260px; margin: 0; color: #748198; font-size: .78rem; line-height: 1.55; }
        .dashboard-metrics { margin-top: clamp(30px, 5vw, 46px); }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 14px; }
        .dashboard-card { min-width: 0; padding: 20px; border: 1px solid #e1e8f0; border-radius: 14px; background: #fff; box-shadow: 0 5px 15px rgba(32,51,80,.035); }
        .dashboard-card-label { margin: 0 0 15px; color: #718099; font-size: .68rem; font-weight: 750; letter-spacing: .12em; text-transform: uppercase; }
        .dashboard-card h3 { margin: 0 0 7px; color: #233651; font-size: clamp(1.3rem, 3vw, 1.8rem); font-weight: 740; letter-spacing: -.035em; overflow-wrap: anywhere; }
        .dashboard-card > p:not(.dashboard-card-label) { margin: 0; color: #65748b; font-size: .8rem; line-height: 1.55; }
        .dashboard-card .dashboard-context { margin-top: 9px !important; color: #748198 !important; font-size: .74rem !important; }
        @media (max-width: 520px) {
            .welcome-panel { padding: 24px 20px; border-radius: 15px; }
            .welcome-panel::after { top: -160px; right: -180px; }
            .welcome-grid { grid-template-columns: 1fr; }
            .welcome-card { min-height: 140px; }
        }
        @media (prefers-reduced-motion: reduce) { .welcome-card { transition: none; } }
    </style>
@endsection
