<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActualizarConfiguracionRequest;
use App\Models\Configuracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pantalla de Configuración: los parámetros del sistema
 * ({@see Configuracion::PARAMETROS}), en una sección por grupo.
 *
 * Un solo permiso, `Update:Configuracion`, abre toda la pantalla. Cada sección
 * se guarda por separado.
 */
class ConfiguracionController extends Controller
{
    public function edit(): View
    {
        $this->autorizarPermiso('Update:Configuracion');

        $grupos = Configuracion::porGrupo();

        // La fila guardada de cada parámetro, con quién la cambió por última
        // vez. Un parámetro que nunca se guardó no tiene fila.
        $guardados = Configuracion::query()
            ->with('editor')
            ->whereIn('clave', array_merge(...array_values(array_map('array_keys', $grupos))))
            ->get()
            ->keyBy('clave');

        return view('configuracion.edit', compact('grupos', 'guardados'));
    }

    /**
     * Guarda los parámetros de una sección.
     */
    public function update(ActualizarConfiguracionRequest $request, string $grupo): RedirectResponse
    {
        foreach ($request->valores() as $clave => $valor) {
            Configuracion::guardar($clave, $valor);
        }

        return redirect()
            ->to(route('configuracion.edit').'#grupo-'.$grupo)
            ->with('estado', 'Se guardó «'.Configuracion::GRUPOS[$grupo]['titulo'].'».');
    }
}
