<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActualizarConfiguracionRequest;
use App\Models\Configuracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Pantalla de Configuración: los parámetros del sistema
 * ({@see Configuracion::PARAMETROS}), en una sección por grupo.
 *
 * Un solo permiso, `Update:Configuracion`, abre toda la pantalla. Cada sección
 * se guarda por separado, **desde un mes y con un motivo**: no pisa lo
 * anterior, agrega una vigencia. Debajo de cada sección va su historial.
 */
class ConfiguracionController extends Controller
{
    public function edit(): View
    {
        $this->autorizarPermiso('Update:Configuracion');

        $grupos = Configuracion::porGrupo();
        $claves = array_merge(...array_values(array_map('array_keys', $grupos)));

        // Lo que rige hoy, por clave. Un parámetro que nunca se cargó no tiene
        // fila y rige su valor por defecto.
        $vigentes = collect($claves)
            ->mapWithKeys(fn (string $clave): array => [$clave => Configuracion::filaVigente($clave)])
            ->filter();

        // El historial de cada sección: una entrada por mes de vigencia, con
        // sus parámetros adentro, del más nuevo al más viejo.
        $filas = Configuracion::query()
            ->with('registrador')
            ->whereIn('clave', $claves)
            ->orderByDesc('vigente_desde')
            ->get();

        $historial = collect($grupos)->map(fn (array $parametros): Collection => $filas
            ->filter(fn (Configuracion $fila): bool => array_key_exists($fila->clave, $parametros))
            ->groupBy(fn (Configuracion $fila): string => $fila->vigente_desde->toDateString())
            ->map(fn (Collection $delMes): Collection => $delMes->keyBy('clave')));

        return view('configuracion.edit', compact('grupos', 'vigentes', 'historial'));
    }

    /**
     * Carga los parámetros de una sección desde el mes elegido.
     */
    public function update(ActualizarConfiguracionRequest $request, string $grupo): RedirectResponse
    {
        $desde = $request->desde();
        $motivo = trim((string) $request->validated('motivo'));

        foreach ($request->valores() as $clave => $valor) {
            Configuracion::guardar($clave, $valor, $desde, $motivo);
        }

        return redirect()
            ->to(route('configuracion.edit').'#grupo-'.$grupo)
            ->with('estado', 'Se guardó «'.Configuracion::GRUPOS[$grupo]['titulo'].'», vigente desde '
                .$desde->translatedFormat('F \d\e Y').'.');
    }
}
