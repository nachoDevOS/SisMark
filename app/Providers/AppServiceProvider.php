<?php

namespace App\Providers;

use App\Database\SqlServer2008Connection;
use App\Models\Licencia;
use App\Models\Role;
use App\Models\SistemaExterno;
use App\Policies\RolePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as Vista;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El servidor SIA corre SQL Server 2008 R2, que no soporta OFFSET/FETCH.
        // Toda conexión sqlsrv usa la variante con paginación por ROW_NUMBER().
        Connection::resolverFor('sqlsrv', function ($connection, $database, $prefix, $config) {
            return new SqlServer2008Connection($connection, $database, $prefix, $config);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registro explícito de la policy del modelo Role (App\Models\Role,
        // que extiende el de Spatie con SoftDeletes) para no depender solo del
        // autodescubrimiento.
        Gate::policy(Role::class, RolePolicy::class);

        // super_admin puede todo, sin permisos individuales asignados.
        Gate::before(fn ($user): ?bool => $user->hasRole('super_admin') ? true : null);

        // Cuántas solicitudes de licencia esperan decisión, para el aviso de la
        // barra superior. Va por composer y no dentro del Blade para que la
        // vista no consulte la base, y solo se cuenta si hay alguien en sesión
        // que pueda resolverlas: al resto el aviso no le sirve de nada.
        // El escritorio va aparte de `layouts.app`: con `@extends`, la vista
        // hija se arma antes que el layout, así que lo que se comparte con el
        // layout no le llega.
        View::composer(['layouts.app', 'dashboard.index'], function (Vista $vista): void {
            $usuario = auth()->user();

            $vista->with('licenciasPendientes', $usuario?->can('viewAny', Licencia::class)
                ? Licencia::solicitudesPendientes()
                : 0);
        });

        $this->configurarTokensDeSistemas();

        // Límite de la API de asistencia. Va por consumidor y no por IP: el
        // sistema consumidor llama desde su servidor, así que todos sus pedidos
        // —los de los ~4.600 funcionarios— llegan con la misma IP, y limitar por
        // ahí dejaría sin servicio a todos por el tráfico normal de la
        // institución. Con el token resuelto a su `SistemaExterno`, cada
        // consumidor tiene su propia cuota y el exceso de uno no castiga al otro.
        RateLimiter::for('api', function (Request $request): Limit {
            $sistema = $request->user();

            return Limit::perMinute(300)->by(
                $sistema instanceof SistemaExterno ? 'sistema:'.$sistema->id : $request->ip()
            );
        });

        // La vista de paginación por defecto de Laravel usa clases Tailwind
        // que este layout no compila; se reemplaza por una vista propia
        // (resources/views/vendor/pagination/custom.blade.php) acorde al
        // estilo del sitio, aplicada a todas las tablas paginadas.
        Paginator::defaultView('vendor.pagination.custom');
    }

    /**
     * Regla propia de vigencia para los tokens de los sistemas consumidores.
     *
     * Sanctum solo mira si el token existe y no venció. Acá se agrega el
     * interruptor: apagar o dar de baja un sistema tiene que cortarle el acceso
     * en el **próximo pedido**, sin ir a borrarle los tokens uno por uno. Así
     * una falsa alarma se revierte volviendo a encenderlo, en vez de coordinar
     * una credencial nueva con el otro equipo.
     *
     * Los tokens que no sean de un `SistemaExterno` siguen con la regla de
     * siempre: acá no se les cambia nada.
     */
    private function configurarTokensDeSistemas(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            function (PersonalAccessToken $token, bool $esValido): bool {
                $duenio = $token->tokenable;

                if (! $duenio instanceof SistemaExterno) {
                    return $esValido;
                }

                if (! $duenio->activo || $duenio->trashed()) {
                    return false;
                }

                return $esValido;
            }
        );
    }
}
