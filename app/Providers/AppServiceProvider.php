<?php

namespace App\Providers;

use App\Database\SqlServer2008Connection;
use App\Models\Licencia;
use App\Models\Role;
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
