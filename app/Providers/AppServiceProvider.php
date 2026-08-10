<?php

namespace App\Providers;

use App\Database\SqlServer2008Connection;
use App\Models\Role;
use App\Policies\RolePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        // Límite de la API de asistencia. Va por clave y no por IP: el sistema
        // consumidor llama desde su servidor, así que todos sus pedidos —los de
        // los ~4.600 funcionarios— llegan con la misma IP, y limitar por ahí
        // dejaría sin servicio a todos por el tráfico normal de la institución.
        // Con la clave, cada consumidor tiene su propia cuota.
        RateLimiter::for('api', function (Request $request): Limit {
            $clave = $request->header('X-API-KEY') ?: $request->bearerToken();

            return Limit::perMinute(300)->by($clave ?: $request->ip());
        });

        // La vista de paginación por defecto de Laravel usa clases Tailwind
        // que este layout no compila; se reemplaza por una vista propia
        // (resources/views/vendor/pagination/custom.blade.php) acorde al
        // estilo del sitio, aplicada a todas las tablas paginadas.
        Paginator::defaultView('vendor.pagination.custom');
    }
}
