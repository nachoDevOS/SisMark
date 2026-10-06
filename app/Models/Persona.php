<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Funcionario en la base local (MySQL), migrado desde «Personas» del SIA.
 *
 * Usa la conexión por defecto (MySQL), tiene id propio, timestamps y
 * eliminación lógica. El carnet es la columna única `ci`.
 */
class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    protected $table = 'personas';

    /**
     * Las URLs de funcionarios usan el CI, no el id autoincremental. El `ci`
     * local ya viene sin relleno (la copia lo recorta), así que basta el
     * binding por columna sin lógica de padding.
     */
    public function getRouteKeyName(): string
    {
        return 'ci';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ci',
        'origenId',
        'paterno',
        'materno',
        'nombres',
        'fechaNacimiento',
        'lugarNacimiento',
        'sexo',
        'estadoCivil',
        'codigoProfesion',
        'nivelEstudio',
        'telefono',
        'direccion',
        'correo',
        'marcaDirecta',
        'pinReloj',
        'observacion',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fechaNacimiento' => 'datetime',
            'marcaDirecta' => 'boolean',
        ];
    }

    public function marcaciones(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'ci', 'ci');
    }

    public function profesion(): BelongsTo
    {
        return $this->belongsTo(Profesion::class, 'codigoProfesion', 'codigoProfesion');
    }

    public function licencias(): HasMany
    {
        return $this->hasMany(Licencia::class, 'ci', 'ci');
    }

    /**
     * Filtra por CI o nombre: cada palabra debe aparecer en el CI o en alguno de
     * los nombres, así "ignacio molina" cruza nombres + paterno.
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (self::terminos($texto) as $termino) {
            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhere('nombres', 'like', "%{$termino}%")
                ->orWhere('paterno', 'like', "%{$termino}%")
                ->orWhere('materno', 'like', "%{$termino}%"));
        }

        return $query;
    }

    /**
     * Un término contra las tres columnas de nombre. Para usar dentro de
     * whereHas('persona') al buscar marcaciones por nombre.
     */
    public function scopeCoincideNombre(Builder $query, string $termino): Builder
    {
        return $query->where('nombres', 'like', "%{$termino}%")
            ->orWhere('paterno', 'like', "%{$termino}%")
            ->orWhere('materno', 'like', "%{$termino}%");
    }

    /**
     * Parte el texto de búsqueda en palabras no vacías.
     *
     * @return list<string>
     */
    public static function terminos(string $texto): array
    {
        return preg_split('/\s+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Nombre completo (nombres + paterno + materno).
     */
    public function getNombreCompletoAttribute(): string
    {
        return collect([$this->nombres, $this->paterno, $this->materno])
            ->map(fn (?string $parte): string => trim((string) $parte))
            ->filter()
            ->implode(' ');
    }
}
