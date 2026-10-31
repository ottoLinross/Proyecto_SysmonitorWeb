<nav aria-label="Módulos de SysMonitor">
    <ul class="sm-nav-list">
        <li>
            <a class="sm-nav-link" href="{{ url('/') }}" @if (request()->path() === '/') aria-current="page" @endif>
                <span class="sm-nav-mark" aria-hidden="true">IN</span><span>Inicio</span>
            </a>
        </li>
        <li>
            <a class="sm-nav-link" href="{{ route('processes.index') }}" @if (request()->routeIs('processes.*')) aria-current="page" @endif>
                <span class="sm-nav-mark" aria-hidden="true">M1</span><span>Procesos</span>
            </a>
        </li>
        <li>
            <a class="sm-nav-link" href="{{ route('cpu-memory.index') }}" @if (request()->routeIs('cpu-memory.*')) aria-current="page" @endif>
                <span class="sm-nav-mark" aria-hidden="true">M2</span><span>CPU y Memoria</span>
            </a>
        </li>
        <li>
            <a class="sm-nav-link" href="{{ route('scheduling.index') }}" @if (request()->routeIs('scheduling.*')) aria-current="page" @endif>
                <span class="sm-nav-mark" aria-hidden="true">M3</span><span>Planificación</span>
            </a>
        </li>
        <li>
            <a class="sm-nav-link" href="{{ route('deadlocks.index') }}" @if (request()->routeIs('deadlocks.*')) aria-current="page" @endif>
                <span class="sm-nav-mark" aria-hidden="true">M4</span><span>Interbloqueos</span>
            </a>
        </li>
        <li>
            <a class="sm-nav-link" href="{{ route('storage.index') }}" @if (request()->routeIs('storage.*')) aria-current="page" @endif>
                <span class="sm-nav-mark" aria-hidden="true">M5</span><span>Almacenamiento</span>
            </a>
        </li>
        @if (auth('web')->user()?->isAdmin())
            <li>
                <a class="sm-nav-link" href="{{ route('users.index') }}" @if (request()->routeIs('users.*')) aria-current="page" @endif>
                    <span class="sm-nav-mark" aria-hidden="true">U</span><span>Usuarios</span>
                </a>
            </li>
        @endif
    </ul>
</nav>
