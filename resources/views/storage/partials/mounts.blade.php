@foreach (['active' => $activeMounts, 'configured' => $configuredMounts] as $kind => $result)
    <h3>{{ $kind === 'active' ? 'Montajes activos (findmnt)' : 'Montajes configurados (/etc/fstab)' }}</h3>
    @if ($kind === 'configured')
        <p>La configuración no implica que el montaje esté activo. El destino «none» puede corresponder a swap.</p>
    @endif
    @if (! $result['available'])
        <p role="status">{{ $kind === 'active' ? 'No se pudieron consultar los montajes activos.' : 'No se pudo leer /etc/fstab.' }}</p>
    @elseif ($result['mounts'] === [])
        <p>{{ $kind === 'active' ? 'No se encontraron montajes activos.' : 'No se encontraron montajes configurados válidos.' }}</p>
    @else
        <div class="table-scroll" role="region" aria-label="{{ $kind === 'active' ? 'Montajes activos' : 'Montajes configurados' }}" tabindex="0">
            <table>
                <caption>{{ $kind === 'active' ? 'Origen, destino y sistema de archivos de los montajes activos' : 'Entradas válidas de /etc/fstab' }}</caption>
                <thead><tr><th scope="col">Origen</th><th scope="col">Destino</th><th scope="col">Sistema de archivos</th>@if ($kind === 'configured')<th scope="col">Opciones</th>@endif</tr></thead>
                <tbody>
                    @foreach ($result['mounts'] as $mount)
                        <tr><td>{{ $mount['source'] }}</td><td>{{ $mount['target'] }}</td><td>{{ $mount['fstype'] }}</td>@if ($kind === 'configured')<td>{{ $mount['options'] }}</td>@endif</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endforeach
