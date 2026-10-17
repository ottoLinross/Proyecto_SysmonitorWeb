<li>
    <dl>
        @foreach (['name' => 'NAME', 'path' => 'PATH', 'type' => 'TYPE', 'size' => 'SIZE (bytes)', 'fstype' => 'FSTYPE', 'model' => 'MODEL'] as $field => $label)
            <dt>{{ $label }}</dt>
            <dd>{{ $device[$field] ?? '—' }}</dd>
        @endforeach
        <dt>MOUNTPOINTS</dt>
        <dd>{{ implode(', ', array_filter($device['mountpoints'] ?? [], fn ($mount) => $mount !== null && $mount !== '')) ?: '—' }}</dd>
    </dl>
    @if ($device['children'] !== [])
        <ul>
            @foreach ($device['children'] as $child)
                @include('storage.partials.device', ['device' => $child])
            @endforeach
        </ul>
    @endif
</li>
