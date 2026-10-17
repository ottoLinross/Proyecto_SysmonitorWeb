@if (! $usage['available'])
    <p role="status">No se pudo consultar el uso del espacio.</p>
@elseif ($usage['filesystems'] === [])
    <p>No se encontraron sistemas de archivos montados.</p>
@else
    <div class="table-scroll" role="region" aria-label="Uso del espacio por sistema de archivos" tabindex="0">
        <table>
            <caption>Sistemas de archivos montados — df -hP</caption>
            <thead>
                <tr>
                    <th scope="col">Sistema de archivos</th>
                    <th scope="col">Tamaño</th>
                    <th scope="col">Usado</th>
                    <th scope="col">Disponible</th>
                    <th scope="col">Uso (%)</th>
                    <th scope="col">Punto de montaje</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($usage['filesystems'] as $filesystem)
                    <tr>
                        @foreach (['filesystem', 'size', 'used', 'available', 'use_percent', 'mountpoint'] as $field)
                            <td>
                                {{ $filesystem[$field] }}
                                @if ($field === 'use_percent')
                                    <div class="usage-bar" role="progressbar"
                                        aria-label="Ocupación de {{ $filesystem['filesystem'] }} en {{ $filesystem['mountpoint'] }}"
                                        aria-valuenow="{{ (int) $filesystem['use_percent'] }}"
                                        aria-valuemin="0" aria-valuemax="100">
                                        <div class="usage-bar-fill" style="width: {{ (int) $filesystem['use_percent'] }}%" aria-hidden="true"></div>
                                    </div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
