{{-- Recorrido iterativo de presentación para no anidar llamadas Blade en árboles profundos. --}}
@php
    $events = [];
    foreach (array_reverse($processTree) as $node) {
        $events[] = ['node' => $node];
    }
@endphp
<ul>
    @while ($events !== [])
        @php
            $event = array_pop($events);
        @endphp
        @if (isset($event['close']))
            </ul>
            </li>
        @else
            @php
                $node = $event['node'];
            @endphp
            <li data-tree-pid="{{ $node['pid'] }}">
                <div class="tree-node">
                    <strong>PID {{ $node['pid'] }}</strong>
                    <span class="tree-command">{{ $node['command'] }}</span>
                    <span class="tree-meta">Usuario: {{ $node['user'] }} · Estado: {{ $node['state'] }}</span>
                </div>
                @if ($node['children'] !== [])
                    <ul>
                    @php
                        $events[] = ['close' => true];
                        foreach (array_reverse($node['children']) as $child) {
                            $events[] = ['node' => $child];
                        }
                    @endphp
                @else
                    </li>
                @endif
        @endif
    @endwhile
</ul>
