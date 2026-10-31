@extends('layouts.sysmonitor')

@section('title', 'Historial de interbloqueos')

@section('content')
    <div class="module-page module-deadlocks">

            <h1>Historial de simulaciones educativas de M4</h1>
        <p role="alert">{{ $message }}</p>
        <p><a href="{{ route('deadlocks.history.index') }}">Consultar simulaciones guardadas</a></p>
        <p><a href="{{ route('deadlocks.index') }}">Volver al simulador</a></p>
    
    </div>
@endsection
