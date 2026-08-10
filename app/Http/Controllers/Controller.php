<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Exige un permiso por nombre, para las pantallas que no cuelgan de un
     * modelo y por eso no tienen policy: el Escritorio y los Reportes.
     *
     * `$this->authorize()` necesita un modelo o una clase con policy; acá lo
     * que se autoriza es la pantalla en sí. El `can()` es el de
     * spatie/laravel-permission, así que respeta el `Gate::before` que le da
     * todo a super_admin (ver AppServiceProvider).
     */
    protected function autorizarPermiso(string $permiso): void
    {
        abort_unless((bool) request()->user()?->can($permiso), 403);
    }

    /**
     * Cantidad de filas por página del listado, tomada de `?por_pagina=` y
     * acotada a los valores permitidos del selector «Mostrar N registros».
     */
    protected function porPagina(Request $request, int $defecto = 10): int
    {
        $porPagina = (int) $request->query('por_pagina', $defecto);

        return in_array($porPagina, [10, 25, 50, 100], true) ? $porPagina : $defecto;
    }
}
