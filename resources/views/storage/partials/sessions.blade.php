@if (! $activeSessions['available'])
    <p role="status">No se pudieron consultar las sesiones activas.</p>
@elseif ($activeSessions['sessions'] === [])
    <p>No hay sesiones activas reportadas.</p>
@else
    <div class="table-scroll">
        <table>
            <caption>Sesiones activas (who, solo lectura)</caption>
            <thead><tr>
                <th scope="col">Nombre de usuario</th>
                <th scope="col">Terminal</th>
                <th scope="col">Inicio de sesión</th>
                <th scope="col">Host u origen</th>
            </tr></thead>
            <tbody>
                @foreach ($activeSessions['sessions'] as $session)
                    <tr>
                        <td>{{ $session['username'] }}</td>
                        <td>{{ $session['terminal'] }}</td>
                        <td>{{ $session['login_at'] }}</td>
                        <td>{{ $session['origin'] ?? 'No reportado' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
