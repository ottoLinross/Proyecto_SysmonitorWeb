<p>Exploración de solo lectura dentro de la raíz del proyecto.</p>
@if (! $explorer['available'])
    <p role="status">{{ $explorer['message'] }}</p>
    <p><a href="{{ route('storage.index', ['path' => '']) }}">Volver a la raíz</a></p>
@else
    <p>Directorio actual: /{{ $explorer['path'] }}</p>
    @if ($explorer['parent'] !== null)
        <p><a href="{{ route('storage.index', ['path' => $explorer['parent']]) }}">Directorio padre</a></p>
    @endif
    @if ($explorer['entries'] === [])
        <p>El directorio está vacío.</p>
    @else
        <div class="table-scroll">
            <table>
                <caption>Archivos y directorios (solo lectura)</caption>
                <thead><tr>
                    <th scope="col">Nombre</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Permisos Unix</th>
                    <th scope="col">Propietario</th>
                    <th scope="col">Tamaño</th>
                </tr></thead>
                <tbody>
                    @foreach ($explorer['entries'] as $entry)
                        <tr>
                            <td>
                                @if ($entry['path'] !== null)
                                    <a href="{{ route('storage.index', ['path' => $entry['path']]) }}">{{ $entry['name'] }}</a>
                                @else
                                    {{ $entry['name'] }}
                                @endif
                            </td>
                            <td>{{ $entry['type'] }}</td>
                            <td><code>{{ $entry['permissions'] }}</code></td>
                            <td>{{ $entry['owner'] }}</td>
                            <td>{{ $entry['size'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
