{{-- Aviso del reporte procesado cuando no se puede generar.

     Va como parcial y no como redirección porque esta tabla siempre se pide por
     AJAX —desde la pantalla de reportes y desde la solapa de la ficha—, y una
     redirección la sigue `fetch` sin avisar: terminaba dibujando la pantalla de
     selección entera, con su sidebar y su barra superior, adentro del recuadro
     de la tabla. --}}
<div class="card card--padded">
    <div class="aviso aviso--error" style="margin: 0;">
        {{ $mensaje }}
    </div>
</div>
