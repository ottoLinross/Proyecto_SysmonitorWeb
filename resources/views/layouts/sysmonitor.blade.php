<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7fb">
    <title>@yield('page-title', trim($__env->yieldContent('title', 'Inicio')).' — '.config('app.name', 'SysMonitor Web'))</title>
    <style>
        :root {
            color-scheme: light;
            --sm-ink: #17233a;
            --sm-muted: #6b7890;
            --sm-line: #e3e9f1;
            --sm-canvas: #f4f7fb;
            --sm-panel: #fff;
            --sm-navy: #10213d;
            --sm-navy-soft: #1a3154;
            --sm-blue: #2878d0;
            --sm-blue-soft: #e8f2ff;
            --sm-green: #16866a;
            --sm-shadow: 0 12px 32px rgba(29, 48, 79, .07);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { min-width: 320px; min-height: 100%; background: var(--sm-canvas); }
        body { min-width: 320px; min-height: 100vh; margin: 0; color: var(--sm-ink); background: var(--sm-canvas); }
        a { color: inherit; }
        button, a, summary { -webkit-tap-highlight-color: transparent; }
        :focus-visible { outline: 3px solid #65a8f5; outline-offset: 3px; }
        .sm-skip-link { position: fixed; z-index: 20; top: 12px; left: 12px; padding: 10px 14px; transform: translateY(-160%); border-radius: 8px; color: #fff; background: var(--sm-navy); }
        .sm-skip-link:focus { transform: translateY(0); }
        .sm-topbar { position: sticky; z-index: 10; top: 0; display: flex; min-height: 74px; align-items: center; justify-content: space-between; gap: 20px; padding: 12px 28px; border-bottom: 1px solid var(--sm-line); background: rgba(255,255,255,.96); }
        .sm-brand { display: inline-flex; min-width: 220px; align-items: center; gap: 12px; text-decoration: none; }
        .sm-brand-mark { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #3489e4, #1856a2); box-shadow: 0 5px 12px rgba(40,120,208,.24); font-size: .82rem; font-weight: 800; letter-spacing: .04em; }
        .sm-brand-name { display: block; font-size: 1rem; font-weight: 750; letter-spacing: -.02em; }
        .sm-brand-caption { display: block; margin-top: 2px; color: var(--sm-muted); font-size: .7rem; letter-spacing: .07em; text-transform: uppercase; }
        .sm-topbar-tools { display: flex; align-items: center; justify-content: flex-end; gap: 18px; }
        .sm-user { display: flex; align-items: center; gap: 10px; }
        .sm-user-copy { min-width: 0; text-align: right; }
        .sm-user-name { display: block; max-width: 230px; overflow: hidden; font-size: .86rem; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
        .sm-user-role { display: block; margin-top: 3px; color: var(--sm-muted); font-size: .72rem; }
        .sm-role-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 1px solid #d6e9e3; border-radius: 999px; color: #176b57; background: #eff9f5; font-size: .7rem; font-weight: 700; white-space: nowrap; }
        .sm-role-pill::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .sm-logout { min-height: 38px; padding: 8px 12px; border: 1px solid var(--sm-line); border-radius: 9px; color: #34435d; background: #fff; font: inherit; font-size: .8rem; font-weight: 650; cursor: pointer; }
        .sm-logout:hover { border-color: #c6d4e5; background: #f8faff; }
        .sm-mobile-menu { display: none; position: relative; }
        .sm-mobile-menu summary { display: inline-flex; min-height: 40px; align-items: center; padding: 8px 12px; border: 1px solid var(--sm-line); border-radius: 9px; background: #fff; font-size: .82rem; font-weight: 650; cursor: pointer; list-style: none; }
        .sm-mobile-menu summary::-webkit-details-marker { display: none; }
        .sm-mobile-menu summary::after { margin-left: 10px; color: var(--sm-muted); content: "⌄"; }
        .sm-mobile-menu[open] summary::after { content: "⌃"; }
        .sm-mobile-panel { position: absolute; top: calc(100% + 10px); right: 0; width: min(300px, calc(100vw - 32px)); padding: 10px; border: 1px solid var(--sm-line); border-radius: 14px; background: #fff; box-shadow: var(--sm-shadow); }
        .sm-workspace { display: grid; min-height: calc(100vh - 74px); grid-template-columns: 252px minmax(0, 1fr); }
        .sm-sidebar { display: flex; flex-direction: column; gap: 18px; padding: 25px 16px 20px; border-right: 1px solid #263b5a; color: #dbe5f4; background: var(--sm-navy); }
        .sm-sidebar-label { margin: 0 10px; color: #8ea2bf; font-size: .68rem; font-weight: 750; letter-spacing: .13em; text-transform: uppercase; }
        .sm-sidebar-foot { margin: auto 10px 0; padding-top: 18px; border-top: 1px solid rgba(220,232,248,.12); color: #9aacc5; font-size: .74rem; line-height: 1.55; }
        .sm-nav-list { display: grid; gap: 5px; margin: 0; padding: 0; list-style: none; }
        .sm-nav-link { display: flex; min-height: 46px; align-items: center; gap: 12px; padding: 9px 11px; border: 1px solid transparent; border-radius: 10px; color: #bfcee2; font-size: .86rem; font-weight: 560; text-decoration: none; transition: background .15s ease, color .15s ease, border-color .15s ease; }
        .sm-nav-link:hover { color: #fff; background: rgba(255,255,255,.07); }
        .sm-nav-link[aria-current="page"] { border-color: rgba(119,174,239,.18); color: #fff; background: var(--sm-navy-soft); box-shadow: inset 3px 0 #64a9f4; }
        .sm-nav-mark { display: grid; width: 27px; height: 27px; flex: 0 0 27px; place-items: center; border: 1px solid rgba(220,232,248,.13); border-radius: 8px; color: #aec8e9; font-size: .62rem; font-weight: 800; letter-spacing: .02em; }
        .sm-nav-link[aria-current="page"] .sm-nav-mark { border-color: rgba(116,178,247,.3); color: #a8d0ff; background: rgba(53,127,210,.2); }
        .sm-mobile-panel .sm-nav-link { color: #34435d; }
        .sm-mobile-panel .sm-nav-link:hover { background: #f2f6fb; }
        .sm-mobile-panel .sm-nav-link[aria-current="page"] { border-color: #d5e6fa; color: #145aa2; background: var(--sm-blue-soft); box-shadow: inset 3px 0 var(--sm-blue); }
        .sm-mobile-panel .sm-nav-mark { border-color: #dce5ef; color: #607089; }
        .sm-main-column { display: flex; min-width: 0; flex-direction: column; }
        .sm-page { width: 100%; min-width: 0; flex: 1; padding: clamp(22px, 4vw, 48px); }
        .sm-content { width: 100%; min-width: 0; max-width: 1440px; margin: 0 auto; overflow-wrap: anywhere; }
        .sm-content img, .sm-content svg, .sm-content canvas, .sm-content video { max-width: 100%; }
        .sm-chart-frame, .sm-table-frame { max-width: 100%; overflow-x: auto; overscroll-behavior-inline: contain; }
        .sm-status-region { width: 100%; max-width: 1440px; margin: 22px auto 0; padding: 0 clamp(22px, 4vw, 48px); }
        .sm-footer { padding: 15px clamp(22px, 4vw, 48px); border-top: 1px solid var(--sm-line); color: var(--sm-muted); font-size: .72rem; }
        @media (max-width: 900px) {
            .sm-topbar { padding-right: 20px; padding-left: 20px; }
            .sm-workspace { grid-template-columns: 218px minmax(0, 1fr); }
            .sm-sidebar { padding-right: 12px; padding-left: 12px; }
            .sm-user-name { max-width: 160px; }
        }
        @media (max-width: 720px) {
            .sm-topbar { min-height: 68px; flex-wrap: wrap; gap: 10px; padding: 10px 16px; }
            .sm-brand { min-width: 0; }
            .sm-brand-caption { display: none; }
            .sm-topbar-tools { gap: 9px; }
            .sm-mobile-menu { display: block; }
            .sm-user { gap: 7px; }
            .sm-user-copy { display: none; }
            .sm-workspace { min-height: calc(100vh - 68px); grid-template-columns: minmax(0, 1fr); }
            .sm-sidebar { display: none; }
            .sm-page { padding: 24px 18px 32px; }
            .sm-status-region { padding: 0 18px; }
            .sm-footer { padding-right: 18px; padding-left: 18px; }
        }
        @media (max-width: 430px) {
            .sm-brand-name { font-size: .9rem; }
            .sm-brand-mark { width: 36px; height: 36px; }
            .sm-topbar-tools { gap: 7px; }
            .sm-role-pill { padding: 5px 7px; font-size: .65rem; }
            .sm-logout { min-height: 38px; padding: 7px 9px; font-size: .75rem; }
            .sm-mobile-menu summary { min-height: 38px; padding: 7px 9px; font-size: .75rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
        }
    </style>
    @yield('styles')
    @stack('styles')
</head>
<body>
<a class="sm-skip-link" href="#main-content">Saltar al contenido principal</a>
<div class="sysmonitor-app">
    <header class="sm-topbar">
        <a class="sm-brand" href="{{ url('/') }}" aria-label="SysMonitor Web, inicio">
            <span class="sm-brand-mark" aria-hidden="true">SM</span>
            <span>
                <span class="sm-brand-name">SysMonitor Web</span>
                <span class="sm-brand-caption">Centro de monitoreo</span>
            </span>
        </a>

        <div class="sm-topbar-tools">
            @php
                $layoutUser = auth('web')->user();
                $currentRole = $layoutUser?->role;
                $roleLabel = match ($currentRole) {
                    \App\Models\User::ROLE_ADMIN => 'Administrador',
                    \App\Models\User::ROLE_OBSERVER => 'Observador',
                    default => 'Rol no reconocido',
                };
            @endphp
            <details class="sm-mobile-menu">
                <summary aria-label="Abrir navegación de módulos">Módulos</summary>
                <div class="sm-mobile-panel">
                    @include('partials.sysmonitor-navigation')
                </div>
            </details>
            <div class="sm-user" aria-label="Sesión de {{ $layoutUser?->name ?? 'Usuario' }}">
                <div class="sm-user-copy">
                    <span class="sm-user-name">{{ $layoutUser?->name ?? 'Usuario' }}</span>
                    <span class="sm-user-role">Sesión activa</span>
                </div>
                <span class="sm-role-pill">{{ $roleLabel }}</span>
            </div>
            @if (trim($__env->yieldContent('show-logout', 'yes')) !== 'no')
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="sm-logout" type="submit">Cerrar sesión</button>
                </form>
            @endif
        </div>
    </header>

    <div class="sm-workspace">
        <aside class="sm-sidebar" aria-label="Navegación principal">
            <p class="sm-sidebar-label">Módulos</p>
            @include('partials.sysmonitor-navigation')
            <p class="sm-sidebar-foot">Operaciones del sistema<br>Vista integrada de SysMonitor</p>
        </aside>

        <div class="sm-main-column">
            @hasSection('status')
                <div class="sm-status-region" role="status" aria-live="polite">@yield('status')</div>
            @endif
            <main class="sm-page" id="main-content" tabindex="-1">
                <div class="sm-content">
                    @yield('content')
                </div>
            </main>
            <footer class="sm-footer">SysMonitor Web <span aria-hidden="true">·</span> Monitoreo de recursos y operaciones</footer>
        </div>
    </div>
</div>
@yield('scripts')
@stack('scripts')
</body>
</html>
