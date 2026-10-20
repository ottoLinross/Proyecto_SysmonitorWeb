<section aria-labelledby="summary-title">
    <h2 id="summary-title">Resumen del almacenamiento</h2>
    <dl class="summary-grid">
        @foreach (['disks' => 'Discos detectados', 'partitions' => 'Particiones detectadas', 'mounts' => 'Sistemas de archivos montados', 'users' => 'Usuarios del sistema', 'sessions' => 'Sesiones activas'] as $key => $label)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $summary[$key] ?? '—' }}</dd>
            </div>
        @endforeach
        <div>
            <dt>Almacenamiento usado y total</dt>
            <dd>No disponible como total global fiable.</dd>
        </div>
    </dl>
    <p>El conteo de montajes corresponde a destinos activos distintos de findmnt; no implica sistemas de archivos independientes. Los usuarios incluyen todas las cuentas válidas.</p>
    <p>No se suman capacidades de discos, particiones ni montajes. Los valores de df están redondeados y varios montajes pueden compartir almacenamiento. Consulte el uso por sistema de archivos en «Uso del espacio».</p>
    <h3>Estado de las fuentes</h3>
    <dl class="summary-grid">
        @foreach (['devices' => 'Dispositivos (lsblk)', 'usage' => 'Uso del espacio (df)', 'mounts' => 'Montajes activos (findmnt)', 'configured' => 'Montajes configurados', 'users' => 'Usuarios', 'sessions' => 'Sesiones (who)', 'explorer' => 'Explorador'] as $key => $label)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $summary['sources'][$key] }}</dd>
            </div>
        @endforeach
    </dl>
    <p>Disponible: consulta completada, incluso sin registros. No disponible: fuente inaccesible. Error de lectura: no se pudo completar o interpretar la consulta. «—» indica que no hay un contador disponible.</p>
</section>
