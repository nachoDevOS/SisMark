@props([
    'accion',
    'mensaje' => 'La asignación deja de estar vigente desde el día siguiente.',
    'hasta' => null,
    'origen' => '',
    'etiqueta' => 'Concluir',
])

@php
    // Por defecto se propone hoy: lo habitual es cerrar el horario el mismo día
    // en que se avisa. La fecha se puede cambiar en el modal.
    $fecha = $hasta ?: now()->toDateString();
    $abrirModal = '$store.concluir.abrir('.\Illuminate\Support\Js::from($accion).', '
        .\Illuminate\Support\Js::from($mensaje).', '.\Illuminate\Support\Js::from($fecha).', '
        .\Illuminate\Support\Js::from($origen).')';
@endphp

{{-- Botón para concluir una asignación de horario. Como el de eliminar, no envía
     nada por su cuenta: carga los datos en el modal global (layouts.app), que
     es el que manda el PATCH. --}}
<button type="button" {{ $attributes->merge(['class' => 'btn-icon']) }}
        title="{{ $etiqueta }}" aria-label="{{ $etiqueta }}"
        x-on:click="{!! $abrirModal !!}">
    <x-heroicon-o-check-circle />
</button>
