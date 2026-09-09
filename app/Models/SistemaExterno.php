<?php

namespace App\Models;

use App\Providers\AppServiceProvider;
use App\Traits\RegistersUserEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * Un sistema que consume la API de asistencia de SisMark. Hoy, Mamoré.
 *
 * Los tokens cuelgan de acá y no de un `User` a propósito. Un usuario de
 * servicio compartido obliga a que cortarle el acceso a un consumidor se los
 * corte a todos, y deja el rastro de las consultas mezclado. Con una fila por
 * consumidor, cada uno tiene su propio token, su propio interruptor y su propia
 * baja.
 *
 * `HasApiTokens` funciona sobre cualquier modelo, no solo sobre `User`: el
 * guard de Sanctum se registra con `provider => null` —en `config/sanctum.php`
 * queda `'guard' => []`— y su `hasValidProvider()` acepta cualquier tokenable
 * que use el trait.
 *
 * La regla de vigencia no está acá sino en
 * {@see AppServiceProvider::configurarTokensDeSistemas()}: un
 * sistema apagado o dado de baja deja de autenticar en el próximo pedido,
 * aunque su token siga existiendo.
 */
class SistemaExterno extends Model
{
    use HasApiTokens, RegistersUserEvents, SoftDeletes;

    protected $table = 'sistemas_externos';

    /**
     * El mismo default que la columna, para que una instancia recién creada
     * sepa que está activa sin ir a releerla. Sin esto, el comando que emite el
     * token avisaba «el sistema está inactivo» justo después de crearlo: la
     * base tenía el `1` del default y el modelo en memoria, `null`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'nombre',
        'observaciones',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Los alcances que se le pueden dar a un token, con lo que habilita cada uno.
     *
     * Es la fuente única: la usan el comando que emite tokens y la validación.
     * Un alcance por área, y no uno por endpoint: partirlos más fino obligaría a
     * reemitir el token de Mamoré cada vez que se agrega una ruta.
     *
     * @var array<string, string>
     */
    public const ALCANCES = [
        'asistencia:read' => 'Leer marcaciones y asistencia procesada de un funcionario',
        'licencias:read' => 'Leer las licencias de un funcionario y sus respaldos',
        'licencias:write' => 'Registrar y dar de baja solicitudes de licencia',
        'turnos:read' => 'Leer el horario sugerido',
        'turnos:write' => 'Asignar el horario al dar de alta un contrato',
    ];

    public function scopeActivo(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Revoca todos los tokens vivos del sistema y devuelve cuántos eran.
     *
     * Se usa antes de emitir uno nuevo: un sistema tiene **un solo token vivo**,
     * para que no queden credenciales sueltas que nadie recuerda haber
     * entregado. El costo es que el consumidor queda cortado desde ese momento
     * hasta que cargue el token nuevo.
     */
    public function revocarTokens(): int
    {
        return $this->tokens()->delete();
    }
}
