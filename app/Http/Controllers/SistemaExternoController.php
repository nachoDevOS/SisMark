<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmitirTokenRequest;
use App\Http\Requests\StoreSistemaExternoRequest;
use App\Http\Requests\UpdateSistemaExternoRequest;
use App\Models\SistemaExterno;
use App\Models\SistemaExternoAuditoria;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Alta, baja y tokens de los sistemas que consumen la API de asistencia.
 *
 * Emitir un token acá es dar acceso a las marcaciones y la asistencia procesada
 * de los ~4.600 funcionarios, con una credencial que no caduca. Por eso la
 * emisión no viene incluida en el permiso de editar la ficha: pide el suyo
 * (`Token:SistemaExterno`), la contraseña de quien la hace, y queda anotada en
 * la bitácora con nombre, IP y hora.
 *
 * Hasta ahora esto se hacía solo por consola, con `php artisan sismark:token`.
 * El comando queda: es el camino cuando la pantalla no está disponible —un
 * despliegue nuevo, sin usuarios ni roles cargados todavía—.
 */
class SistemaExternoController extends Controller
{
    /**
     * Listado de consumidores.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', SistemaExterno::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $porPagina = $this->porPagina($request);

        return view('tokens-api.index', compact('buscar', 'porPagina'));
    }

    /**
     * El listado para AJAX.
     */
    public function list(Request $request): View
    {
        $this->authorize('viewAny', SistemaExterno::class);

        $buscar = trim((string) $request->query('q', ''));

        $sistemas = SistemaExterno::query()
            ->withCount('tokens')
            ->when($buscar !== '', fn (Builder $query) => $query
                ->where(fn (Builder $sub) => $sub
                    ->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('slug', 'like', "%{$buscar}%")))
            // Los apagados al final: un sistema inactivo no consume nada y no es
            // lo que se viene a mirar.
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->paginate($this->porPagina($request))
            ->withQueryString();

        return view('tokens-api.list', compact('sistemas'));
    }

    public function create(): View
    {
        $this->authorize('create', SistemaExterno::class);

        return view('tokens-api.create', ['sistema' => new SistemaExterno]);
    }

    /**
     * Registra el sistema, o **reactiva** el que estaba dado de baja con ese
     * mismo nombre corto.
     *
     * El índice único de `sistemas_externos` no distingue `deleted_at`, así que
     * insertar sobre un slug dado de baja revienta con clave duplicada. Y
     * rechazarlo en la validación sería peor: ese nombre corto quedaría quemado
     * para siempre, porque los dados de baja no se listan y no hay pantalla
     * desde donde recuperarlos.
     *
     * Reactivar es además lo coherente con el módulo: acá la baja es lógica y
     * reversible, igual que el interruptor `activo`.
     *
     * **Lo que no vuelve es el token.** `destroy()` no lo borra —lo deja
     * dormido, para que reactivar desde la baja no obligue a coordinar una
     * credencial nueva—, pero acá el que da de alta puede ser otro equipo que
     * simplemente eligió el mismo nombre corto, y heredar la credencial de un
     * consumidor ajeno sería darle acceso sin que nadie lo decidiera. Se revoca
     * y se emite una nueva, que es una decisión explícita y queda en la
     * bitácora.
     */
    public function store(StoreSistemaExternoRequest $request): RedirectResponse
    {
        $this->authorize('create', SistemaExterno::class);

        $datos = $request->validated();

        $dadoDeBaja = SistemaExterno::onlyTrashed()->where('slug', $datos['slug'])->first();

        if ($dadoDeBaja !== null) {
            $dadoDeBaja->restore();
            $dadoDeBaja->update($datos);

            if ($dadoDeBaja->revocarTokens() > 0) {
                $dadoDeBaja->anotar(
                    SistemaExternoAuditoria::ACCION_REVOCAR,
                    null,
                    [],
                    $request->user()?->getKey(),
                    $request->ip(),
                );
            }

            return redirect()
                ->route('tokens-api.show', $dadoDeBaja)
                ->with('estado', 'Ese sistema estaba dado de baja y se reactivó con los datos nuevos. Su token anterior quedó revocado: emitile uno nuevo.');
        }

        $sistema = SistemaExterno::create($datos);

        return redirect()
            ->route('tokens-api.show', $sistema)
            ->with('estado', 'Sistema registrado. Ahora se le puede emitir un token.');
    }

    /**
     * La ficha: los datos, el token vivo y la bitácora.
     */
    public function show(SistemaExterno $sistema): View
    {
        $this->authorize('view', $sistema);

        $tokens = $sistema->tokens()->orderByDesc('id')->get();

        $bitacora = $sistema->bitacora()
            ->with('usuario:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('tokens-api.show', compact('sistema', 'tokens', 'bitacora'));
    }

    public function edit(SistemaExterno $sistema): View
    {
        $this->authorize('update', $sistema);

        return view('tokens-api.edit', compact('sistema'));
    }

    public function update(UpdateSistemaExternoRequest $request, SistemaExterno $sistema): RedirectResponse
    {
        $this->authorize('update', $sistema);

        $sistema->update($request->validated());

        return redirect()
            ->route('tokens-api.show', $sistema)
            ->with('estado', 'Sistema actualizado.');
    }

    /**
     * Baja lógica. No le borra los tokens: la regla de vigencia de
     * {@see AppServiceProvider} ya rechaza a un sistema dado de
     * baja en el próximo pedido, y conservarlos deja que reactivarlo lo
     * devuelva a andar sin coordinar una credencial nueva.
     */
    public function destroy(SistemaExterno $sistema): RedirectResponse
    {
        $this->authorize('delete', $sistema);

        $sistema->delete();

        return redirect()
            ->route('tokens-api.index')
            ->with('estado', 'Sistema dado de baja. Su token deja de funcionar en el próximo pedido.');
    }

    /**
     * Prende o apaga el sistema.
     *
     * Es el corte reversible: apagarlo rechaza el próximo pedido sin tocar el
     * token, así una falsa alarma se revierte volviéndolo a encender en vez de
     * coordinar una credencial nueva con el otro equipo.
     */
    public function toggleActivo(SistemaExterno $sistema): RedirectResponse
    {
        $this->authorize('update', $sistema);

        $sistema->update(['activo' => ! $sistema->activo]);

        return back()->with('estado', $sistema->activo
            ? 'Sistema activado. Su token vuelve a funcionar.'
            : 'Sistema desactivado. Su token deja de funcionar en el próximo pedido.');
    }

    /**
     * Emite el token del sistema y lo muestra **una sola vez**.
     *
     * La base guarda solo su hash, así que perderlo obliga a emitir otro. Es lo
     * que garantiza que un token filtrado no se pueda leer después desde la
     * base, y por eso la vista lo pone en el flash y no en la tabla.
     *
     * **Sale con todos los alcances.** Elegirlos de a uno en la pantalla obliga
     * a saber de antemano qué endpoints va a usar el consumidor, que es
     * justamente lo que no se sabe al darlo de alta, y equivocarse ahí se
     * manifiesta como un 403 del otro lado que manda a buscar el problema al
     * lado equivocado. El corte fino sigue estando para quien lo necesite:
     * `php artisan sismark:token {slug} --alcance=…`.
     */
    public function emitirToken(EmitirTokenRequest $request, SistemaExterno $sistema): RedirectResponse
    {
        $this->authorize('token', $sistema);

        if (! $sistema->activo) {
            return back()->with('error', 'El sistema está inactivo: activalo antes de emitirle un token.');
        }

        $alcances = array_keys(SistemaExterno::ALCANCES);

        // Un sistema tiene un solo token vivo. Emitir uno nuevo revoca el
        // anterior, para que no queden credenciales sueltas que nadie recuerda
        // haber entregado. El costo es que el consumidor queda cortado desde
        // este momento hasta que cargue el nuevo: no hay ventana de
        // convivencia, y por eso la pantalla avisa antes.
        $revocados = $sistema->revocarTokens();

        $nuevo = $sistema->createToken("servicio-{$sistema->slug}", $alcances);

        $sistema->anotar(
            SistemaExternoAuditoria::ACCION_EMITIR,
            $nuevo->accessToken->getKey(),
            $alcances,
            $request->user()?->getKey(),
            $request->ip(),
        );

        return redirect()
            ->route('tokens-api.show', $sistema)
            ->with([
                'token_emitido' => $nuevo->plainTextToken,
                'token_alcances' => $alcances,
                'token_revocados' => $revocados,
            ]);
    }

    /**
     * Mata un token sin emitir nada a cambio.
     *
     * Con un solo token vivo por sistema, emitir ya revoca lo anterior; esto es
     * el camino de emergencia: un token filtrado que hay que cortar ahora, o un
     * consumidor que dejó de usar la API y no debería conservar credencial.
     *
     * A diferencia de la emisión **no pide contraseña**: acá los segundos
     * cuentan, y lo que se hace es cerrar una puerta, no abrirla. Queda igual en
     * la bitácora.
     */
    public function revocarToken(Request $request, SistemaExterno $sistema, int $token): RedirectResponse
    {
        $this->authorize('token', $sistema);

        // Se busca dentro de los tokens del sistema y no por id suelto: sin eso,
        // el id corrido dejaría revocarle el token a cualquier otro consumidor
        // desde esta pantalla.
        $revocado = $sistema->tokens()->whereKey($token)->delete();

        if ($revocado === 0) {
            return back()->with('error', 'Ese token no existe o ya estaba revocado.');
        }

        $sistema->anotar(
            SistemaExternoAuditoria::ACCION_REVOCAR,
            $token,
            [],
            $request->user()?->getKey(),
            $request->ip(),
        );

        return back()->with('estado', 'Token revocado. El sistema queda sin acceso hasta que se le emita otro.');
    }
}
