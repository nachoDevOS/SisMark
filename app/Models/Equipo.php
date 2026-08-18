<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\EquipoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Representa un equipo biométrico ZKTeco en la red LAN.
 *
 * Cada registro es un dispositivo físico con el que el microservicio Python
 * se comunica por TCP (puerto 4370 por defecto). Laravel nunca habla directo
 * con el equipo: solo lee/escribe estas columnas y delega la comunicación
 * real al microservicio.
 *
 * Usa eliminación lógica (SoftDeletes): destroy() solo marca deleted_at y el
 * equipo desaparece de los listados sin borrarse de la base.
 */
class Equipo extends Model
{
    /** @use HasFactory<EquipoFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'ip',
        'puerto',
        'comm_key',
        'ubicacion',
        'algoritmo',
        'en_linea',
        'ultima_sync',
        'activo',
        'sync_automatica',
        'sync_horarios',
        'sync_dias',
        'sync_ultimo_automatico',
        'sync_ultimo_exito',
        'observacion',
        'estado',
    ];

    /**
     * Casts de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'puerto' => 'integer',
            'comm_key' => 'integer',
            'en_linea' => 'boolean',
            'activo' => 'boolean',
            'sync_automatica' => 'boolean',
            'sync_horarios' => 'array',
            'sync_dias' => 'array',
            'ultima_sync' => 'datetime',
            'sync_ultimo_automatico' => 'datetime',
            'sync_ultimo_exito' => 'datetime',
        ];
    }

    /**
     * Horas del día a las que el equipo se sincroniza solo, normalizadas a
     * «HH:MM» y ordenadas. Vacío si nunca se configuró.
     *
     * @return list<string>
     */
    public function horariosSync(): array
    {
        $horarios = array_values(array_filter(
            array_map(fn ($hora): string => substr(trim((string) $hora), 0, 5), $this->sync_horarios ?? []),
            fn (string $hora): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) === 1,
        ));

        sort($horarios);

        return array_values(array_unique($horarios));
    }

    /**
     * Días de la semana en los que el equipo se sincroniza solo, con los
     * números de {@see Turno::DIAS} (1 = Domingo … 7 = Sábado), ordenados.
     *
     * Vacío significa **todos los días**, y es lo que devuelve un equipo que
     * nunca eligió días: la sincronización automática existía antes que esta
     * columna, y un equipo ya configurado no puede dejar de trabajar porque se
     * agregó un campo.
     *
     * @return list<int>
     */
    public function diasSync(): array
    {
        $dias = array_values(array_unique(array_filter(
            array_map(fn ($dia): int => (int) $dia, $this->sync_dias ?? []),
            fn (int $dia): bool => isset(Turno::DIAS[$dia]),
        )));

        sort($dias);

        return $dias;
    }

    /**
     * Nombres de los días configurados, para mostrarlos en la ficha.
     *
     * @return list<string>
     */
    public function nombresDiasSync(): array
    {
        return array_map(fn (int $dia): string => Turno::DIAS[$dia], $this->diasSync());
    }

    /**
     * Si en el día de la semana indicado le toca trabajar.
     *
     * Sin días elegidos trabaja todos, por lo mismo que explica {@see
     * diasSync()}.
     */
    public function tocaEnElDia(Carbon $momento): bool
    {
        $dias = $this->diasSync();

        // `dayOfWeek` de Carbon es 0 = Domingo; la convención del sistema
        // —heredada del SIA— arranca en 1, así que se corre uno.
        return $dias === [] || in_array($momento->dayOfWeek + 1, $dias, true);
    }

    /**
     * Si al minuto indicado le toca sincronizarse sola.
     *
     * Pide equipo activo, sincronización automática encendida, que el día de la
     * semana esté entre los elegidos y que la hora esté en la lista. El minuto
     * exacto es la unidad: la tarea programada corre cada minuto y compara
     * «HH:MM».
     */
    public function tocaSincronizar(Carbon $momento): bool
    {
        if (! $this->activo || ! $this->sync_automatica) {
            return false;
        }

        if (! $this->tocaEnElDia($momento)) {
            return false;
        }

        return in_array($momento->format('H:i'), $this->horariosSync(), true);
    }

    /**
     * Si ya se sincronizó sola dentro del mismo minuto.
     *
     * Evita repetir el trabajo cuando la tarea programada se dispara dos veces
     * en el mismo minuto (por ejemplo, dos `schedule:run` encimados).
     */
    public function yaSincronizoEn(Carbon $momento): bool
    {
        return $this->sync_ultimo_automatico?->isSameMinute($momento) ?? false;
    }
}
