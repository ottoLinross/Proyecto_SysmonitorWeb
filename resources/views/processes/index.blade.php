@extends('layouts.sysmonitor')

@section('title', 'Procesos')

@section('styles')
    <style>.module-processes *{ box-sizing: border-box; }.module-processes{ margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }.module-processes{ max-width: 1440px; margin: 0 auto; padding: 32px 20px; }.module-processes h1{ margin: 0 0 12px; font-size: 1.8rem; }.module-processes .description{ margin: 0 0 24px; color: #526173; }.module-processes .state-summary{ margin-bottom: 24px; }.module-processes .state-summary h2{ margin: 0 0 8px; font-size: 1.2rem; }.module-processes .state-summary p{ margin: 0 0 16px; color: #526173; }.module-processes .state-cards{ display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 0; }.module-processes .state-card{ padding: 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }.module-processes .state-card dt{ color: #526173; }.module-processes .state-card dd{ margin: 8px 0 0; font-size: 1.8rem; font-weight: 600; font-variant-numeric: tabular-nums; }.module-processes .search-form{ display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 20px; }.module-processes .search-form input[type="text"]{ flex: 1; min-width: 180px; padding: 10px 12px; border: 1px solid #94a3b8; border-radius: 6px; font: inherit; }.module-processes .search-form button{ padding: 10px 18px; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font: inherit; cursor: pointer; }.module-processes a{ color: #1d4ed8; }.module-processes th a{ display: block; color: inherit; text-decoration: none; }.module-processes th a:hover{ text-decoration: underline; }.module-processes .table-container{ max-height: 70vh; overflow: auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; }.module-processes .table-container:focus-visible{ outline: 3px solid #2563eb; outline-offset: 3px; }.module-processes table{ width: 100%; min-width: 1050px; border-spacing: 0; font-size: 0.9rem; }.module-processes caption{ padding: 16px; text-align: left; font-weight: 600; }.module-processes th, .module-processes td{ padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: top; }.module-processes th{ position: sticky; top: 0; background: #eaf0f6; white-space: nowrap; }.module-processes tbody tr:nth-child(even){ background: #f8fafc; }.module-processes tbody tr:hover{ background: #eff6ff; }.module-processes tbody tr:last-child td{ border-bottom: 0; }.module-processes .numeric{ text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }.module-processes .command{ min-width: 320px; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, monospace; }.module-processes .empty{ padding: 28px 16px; text-align: center; color: #526173; }.module-processes .process-tree{ margin-top: 28px; }.module-processes .process-tree h2{ font-size: 1.2rem; }.module-processes .tree-container{ max-height: 70vh; overflow: auto; padding: 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; }.module-processes .tree-container:focus-visible{ outline: 3px solid #2563eb; outline-offset: 3px; }.module-processes .tree-container ul{ list-style: none; padding-left: 24px; margin: 0; min-width: max-content; }.module-processes .tree-container > ul{ padding-left: 0; }.module-processes .tree-container li{ margin: 8px 0; }.module-processes .tree-container li > ul{ border-left: 1px solid #cbd5e1; margin-left: 8px; }.module-processes .tree-node{ display: flex; gap: 12px; align-items: baseline; padding: 8px; }.module-processes .tree-command{ max-width: 80ch; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, monospace; }.module-processes .tree-meta{ color: #526173; }.module-processes .test-processes{ margin-top: 28px; }.module-processes .test-processes h2{ font-size: 1.2rem; }.module-processes .launch-form{ margin: 16px 0; }.module-processes .launch-form button{ padding: 10px 18px; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font: inherit; cursor: pointer; }.module-processes .launch-form button:disabled{ background: #64748b; cursor: not-allowed; }.module-processes .notice{ padding: 12px 16px; border-radius: 6px; }.module-processes .notice-success{ background: #dcfce7; color: #166534; }.module-processes .notice-error{ background: #fee2e2; color: #991b1b; }.module-processes .managed-table{ min-width: 750px; }.module-processes .signal-actions{ display: flex; flex-wrap: wrap; gap: 8px; }.module-processes .signal-form button{ padding: 6px 8px; border: 1px solid #94a3b8; border-radius: 4px; background: #fff; color: #182332; cursor: pointer; }.module-processes .signal-form button:disabled{ color: #64748b; cursor: not-allowed; opacity: 0.6; }.module-processes .priority-form{ display: flex; align-items: center; gap: 8px; }.module-processes .priority-form input{ width: 70px; padding: 6px; }.module-processes .priority-form button{ padding: 6px 8px; white-space: nowrap; }
        </style>
@endsection

@section('content')
    <div class="module-page module-processes">

            <h1 id="processes-title">Módulo de Procesos</h1>
        <p class="description">Información actual de los procesos del sistema.</p>
        <section class="state-summary" aria-labelledby="state-summary-title">
            <h2 id="state-summary-title">Resumen por estados</h2>
            <p>Conteos globales del sistema, independientes de la búsqueda y el orden de la tabla.</p>
            <dl class="state-cards">
                @foreach ($stateLabels as $code => $description)
                    <div class="state-card">
                        <dt>{{ $code }} - {{ $description }}</dt>
                        <dd id="state-count-{{ $code }}">{{ $stateSummary[$code] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
        <form class="search-form" method="GET" action="{{ route('processes.index') }}">
            <label for="process-search">Buscar procesos</label>
            <input id="process-search" type="text" name="q" value="{{ $q }}" placeholder="Buscar en cualquier columna">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <button type="submit">Buscar</button>
            <a href="{{ route('processes.index', ['sort' => $sort, 'direction' => $direction]) }}">Limpiar búsqueda</a>
        </form>
        <div class="table-container" role="region" aria-labelledby="processes-title" tabindex="0">
            <table>
                <caption>Procesos del sistema</caption>
                <thead>
                    <tr>
                        @foreach ($columns as $column => $label)
                            <th scope="col" class="{{ in_array($column, $numericColumns, true) ? 'numeric' : '' }}" aria-sort="{{ $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <a href="{{ route('processes.index', ['q' => $q, 'sort' => $column, 'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc']) }}">
                                    {{ $label }}
                                    @if ($sort === $column)
                                        <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </a>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($processes as $process)
                        <tr>
                            <td class="numeric">{{ $process['pid'] }}</td>
                            <td class="numeric">{{ $process['ppid'] }}</td>
                            <td>{{ $process['user'] }}</td>
                            <td>{{ $process['state'] }}</td>
                            <td class="numeric">{{ $process['nice'] }}</td>
                            <td class="numeric">{{ number_format($process['cpu_percent'], 1, '.', '') }}</td>
                            <td class="numeric">{{ number_format($process['memory_percent'], 1, '.', '') }}</td>
                            <td class="numeric">{{ $process['memory_kb'] }}</td>
                            <td class="command">{{ $process['command'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="empty">
                                @if ($hasProcesses && $q !== '')
                                    No se encontraron procesos que coincidan con la búsqueda.
                                @else
                                    No hay procesos disponibles para mostrar.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <section class="process-tree" aria-labelledby="process-tree-title">
            <h2 id="process-tree-title">Árbol de Procesos</h2>
            @if ($processTree !== [])
                <div class="tree-container" role="region" aria-labelledby="process-tree-title" tabindex="0">
                    @include('processes.partials.tree-node', ['processTree' => $processTree])
                </div>
            @else
                <p>No hay procesos disponibles para construir el árbol.</p>
            @endif
        </section>
        <section class="test-processes" aria-labelledby="test-processes-title">
            <h2 id="test-processes-title">Procesos de prueba</h2>
            <p>Las acciones se limitan a procesos de prueba creados y registrados por SysMonitor. La identidad real se verifica antes de cada acción.</p>
            @if (session('test_process_success'))
                <p class="notice notice-success" role="status">{{ session('test_process_success') }}</p>
            @endif
            @if (session('test_process_error'))
                <p class="notice notice-error" role="alert">{{ session('test_process_error') }}</p>
            @endif
            @if ($managedProcessesUnavailable)
                <p class="notice notice-error" role="alert">No se pudo consultar el registro de procesos de prueba.</p>
            @endif
            <form class="launch-form" method="POST" action="{{ route('processes.test.store') }}">
                @csrf
                <button type="submit" @disabled($managedProcessesUnavailable)>Lanzar proceso de prueba</button>
            </form>
            <div class="table-container" role="region" aria-labelledby="test-processes-title" tabindex="0">
                <table id="managed-process-table" class="managed-table">
                    <caption>Procesos creados por SysMonitor</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="numeric">PID</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Comando controlado</th>
                            <th scope="col" class="numeric">UID</th>
                            <th scope="col">Estado registrado</th>
                            <th scope="col">Fecha/hora de creación</th>
                            <th scope="col">Señales</th>
                            <th scope="col">Prioridad nice</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($managedProcesses as $managedProcess)
                            <tr>
                                <td class="numeric">{{ $managedProcess->pid }}</td>
                                <td>{{ $managedProcess->process_type }}</td>
                                <td class="command">{{ $managedProcess->command_label }}</td>
                                <td class="numeric">{{ $managedProcess->owner_uid }}</td>
                                <td>{{ $managedProcess->status }}</td>
                                <td>{{ $managedProcess->getRawOriginal('launched_at') }}</td>
                                <td>
                                    <div class="signal-actions">
                                        @foreach ($allowedSignals as $signal => $definition)
                                            <form class="signal-form" method="POST" action="{{ route('processes.test.signal', $managedProcess) }}">
                                                @csrf
                                                <input type="hidden" name="signal" value="{{ $signal }}">
                                                <button type="submit" @disabled(! $managedProcessActions[$managedProcess->id])>{{ $definition['label'] }}</button>
                                            </form>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <form class="priority-form" method="POST" action="{{ route('processes.test.priority', $managedProcess) }}">
                                        @csrf
                                        <label for="nice-{{ $managedProcess->id }}">Nuevo nice</label>
                                        <input id="nice-{{ $managedProcess->id }}" type="number" name="nice" min="-20" max="19" step="1" required @disabled(! $managedProcessActions[$managedProcess->id])>
                                        <button type="submit" @disabled(! $managedProcessActions[$managedProcess->id])>Cambiar prioridad</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="empty">No hay procesos de prueba registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    
    </div>
@endsection
