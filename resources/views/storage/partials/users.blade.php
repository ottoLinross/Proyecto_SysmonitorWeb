<p>UID_MIN: {{ $systemUsers['uid_min'] }}.
    @if ($systemUsers['fallback'])
        Se utiliza el valor alternativo 1000 porque /etc/login.defs no está disponible o no contiene un UID_MIN válido.
    @endif
    Clasificación por UID; root se trata explícitamente. Orden por UID y nombre de usuario.
</p>
@if (! $systemUsers['available'])
    <p role="status">No se pudo leer /etc/passwd para consultar los usuarios.</p>
@elseif ($systemUsers['users'] === [])
    <p>No se encontraron usuarios válidos en /etc/passwd.</p>
@else
    <div class="table-scroll">
        <table>
            <caption>Usuarios de /etc/passwd (solo lectura)</caption>
            <thead><tr>
                <th scope="col">Nombre de usuario</th>
                <th scope="col">UID</th>
                <th scope="col">GID</th>
                <th scope="col">Directorio personal</th>
                <th scope="col">Shell</th>
                <th scope="col">Clasificación</th>
            </tr></thead>
            <tbody>
                @foreach ($systemUsers['users'] as $user)
                    <tr>
                        <td>{{ $user['username'] }}</td>
                        <td>{{ $user['uid'] }}</td>
                        <td>{{ $user['gid'] }}</td>
                        <td>{{ $user['home'] }}</td>
                        <td>{{ $user['shell'] }}</td>
                        <td>{{ $user['classification'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
