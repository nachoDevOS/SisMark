<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConcluirAsignacionHorarioRequest;
use App\Models\AsignacionHorario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Concluir o eliminar un horario heredado del sistema anterior (la tabla
 * `asignacion_horarios`, migrada de «AsignacionTurnos» del SIA), desde los
 * botones del panel «Horarios» de la ficha del funcionario.
 *
 * Ya no hay pantalla propia: se asigna un turno ({@see AsignacionTurnoController})
 * y el detalle de un turno se maneja desde el turno.
 *
 * El funcionario se cruza por **CI** (la asignación solo guarda la cédula) y el
 * horario por la FK **`horario_id`**; `idHorario` queda como dato histórico del SIA y
 * no se usa para relacionar.
 */
class AsignacionHorarioController extends Controller
{
    /**
     * Concluye una asignación: le pone fecha de fin y deja de estar vigente.
     *
     * Es lo que corresponde cuando el funcionario **dejó** ese horario: la
     * asignación queda como historia y sigue explicando sus marcaciones y
     * licencias de ese período. Borrarla es otra cosa, y es para cuando se
     * cargó mal ({@see self::destroy()}).
     */
    public function concluir(ConcluirAsignacionHorarioRequest $request, AsignacionHorario $asignacion): RedirectResponse
    {
        $this->authorize('update', $asignacion);
        $this->rechazarSiEsDeTurno($asignacion);

        $asignacion->hasta = Carbon::parse($request->validated('hasta'))->startOfDay();
        $asignacion->save();

        return redirect($this->destino($request, trim((string) $asignacion->ci)))
            ->with('estado', 'Horario concluido el '.$asignacion->hasta->format('d/m/Y').'.');
    }

    /**
     * Elimina (lógicamente) una asignación cargada por error. El motivo y el
     * usuario los graba el trait RegistersUserEvents.
     */
    public function destroy(Request $request, AsignacionHorario $asignacion): RedirectResponse
    {
        $this->authorize('delete', $asignacion);
        $this->rechazarSiEsDeTurno($asignacion);

        $asignacion->delete();

        // El modal global de baja manda el ancla de la solapa desde la que se
        // borró, para volver ahí y no a la primera.
        $ancla = (string) $request->input('ancla', '');

        return redirect(url()->previous().($ancla === 'horarios' ? '#horarios' : ''))
            ->with('estado', 'Asignación de horario eliminada.');
    }

    /**
     * Corta si la fila es detalle de un turno: eso se concluye o se elimina
     * desde «Turnos asignados», con el turno entero.
     *
     * Va acá además de en la policy porque el `Gate::before` de
     * AppServiceProvider le da todo al Administrador sin consultar las policies:
     * sin esto, el Administrador podía dejar un turno desparejo con su detalle.
     */
    private function rechazarSiEsDeTurno(AsignacionHorario $asignacion): void
    {
        abort_if($asignacion->es_de_turno, 403, 'Este horario es parte de un turno asignado: concluí o eliminá el turno desde «Turnos asignados».');
    }

    /**
     * A dónde volver después de guardar: a la ficha desde la que se entró
     * («mamore» o «local») o, si no vino el origen, a la página anterior. Solo
     * se aceptan estos dos orígenes conocidos, así un valor manipulado nunca
     * redirige fuera del sitio.
     */
    private function destino(Request $request, string $ci): string
    {
        // El ancla deja abierta la solapa de horarios al volver a la ficha.
        return match ($this->origen($request)) {
            'mamore' => route('funcionarios.mamore', ['ci' => $ci]).'#horarios',
            'local' => route('funcionarios.show', ['persona' => $ci]).'#horarios',
            default => url()->previous().'#horarios',
        };
    }

    /**
     * Ficha de la que se entró al formulario: `mamore`, `local` o cadena vacía.
     */
    private function origen(Request $request): string
    {
        $origen = (string) $request->input('origen', '');

        return in_array($origen, ['mamore', 'local'], true) ? $origen : '';
    }
}
