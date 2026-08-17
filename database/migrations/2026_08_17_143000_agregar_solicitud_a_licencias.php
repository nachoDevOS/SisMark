<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De qué alta salió cada licencia, para poder mostrarlas agrupadas.
 *
 * Un alta expande el rango a una fila por día y turno: pedir del 14 al 15 de
 * agosto deja **dos**. En la base eso está bien —el índice único
 * `(ci, fecha, turno_id)` es lo que impide licenciar dos veces el mismo turno, y
 * `ProcesadorAsistencia` cruza día contra turno—, pero en pantalla parecen dos
 * licencias distintas y da a entender que hay que aprobarlas por separado.
 *
 * Con esta columna, el listado muestra el pedido que abre cada solicitud —la fila
 * de fecha más temprana— y resuelve aparte hasta qué día llega y cuántos son.
 *
 * ---
 * **Por qué no alcanzaba agrupar por `ci` + `fechaPedido`.**
 *
 * `RegistroLicencia` escribe `fechaPedido` una sola vez por alta, así que parecía
 * servir. Medido sobre las 1.110.345 filas migradas del SIA, no sirve: 688.216
 * (62%) traen la hora en `00:00:00`, de modo que todo lo que una persona pidió
 * cualquier día de ese año cae en el mismo grupo. El más grande llegaba a **10.308
 * filas**, con **7.213 días** entre la primera y la última.
 * ---
 *
 * ---
 * **Cómo hace el listado para mostrar una fila por pedido.**
 *
 * En dos pasos, y nunca agrupando la tabla entera sin acotar:
 *
 * 1. Las solicitudes que cruzan el filtro, con su fecha más reciente:
 *    `GROUP BY solicitud ORDER BY MAX(fecha) DESC`, paginado. Eso es lo que se
 *    cuenta y lo que se pagina: una licencia de cinco días es **una**.
 * 2. De esas diez, la fila que abre cada una, con un `whereIn` delante.
 *
 * Se probó con una sola consulta, `WHERE id = (SELECT … LIMIT 1)` sobre toda la
 * tabla. Sin filtros anda —el `LIMIT 10` corta temprano—, pero **con el buscador
 * se cae**: una búsqueda poco frecuente no encuentra diez coincidencias temprano
 * y termina evaluando la subconsulta en el millón de filas.
 *
 * ```
 * sin filtro                4,1 s        (una sola consulta: 0,0 s)
 * filtro por estado         0,0 s
 * búsqueda poco frecuente   3,4 s        (una sola consulta: ABORTADA >45 s)
 * búsqueda común            3,6 s
 * ```
 *
 * El costo se concentra en la página sin filtrar, que es la página 1 de 26.000 y
 * nadie recorre; los dos flujos reales —filtrar por «Pendiente» y buscar a una
 * persona— quedan bien.
 * ---
 *
 * La columna queda **siempre escrita, nunca en `null`**.
 *
 * ---
 * **Lo que ya está en la tabla se agrupa por `ci` + `fechaPedido` + `motivo`.**
 *
 * Es la misma clave que usa `sia:migrar-licencias`, así que una base que ya tenía
 * datos queda igual que una recién copiada. El SIA no guarda «el pedido», pero sí
 * de qué alta salió cada fila, y eso alcanza.
 *
 * Medido sobre las 1.110.346 filas reales: 256.422 solicitudes, 4,3 filas de
 * promedio. Los grupos grandes son licencias que de verdad lo son —«CUARENTENA
 * TOTAL» de abril a junio de 2020, 117 filas; un memorándum retroactivo de 20
 * años, 10.308—, no mezclas de pedidos distintos. Agrupar solo por
 * `ci + fechaPedido`, sin el motivo, sí los mezclaba.
 *
 * El identificador se deriva de la clave con `sha2` en vez de sortearse: es
 * estable entre corridas, así que reejecutar la copia del SIA no cambia los
 * agrupamientos. Las altas nuevas usan un ULID, que se genera en PHP antes del
 * `insert()` masivo.
 * ---
 */
return new class extends Migration
{
    /**
     * Filas por bloque del relleno. La tabla pasa el millón: un solo UPDATE la
     * deja tomada durante todo el despliegue.
     */
    private const LOTE = 50000;

    public function up(): void
    {
        Schema::table('licencias', function (Blueprint $table): void {
            $table->char('solicitud', 26)->nullable()->after('fechaPedido');
            // De dónde salió la licencia: «propio» si la cargó Recursos Humanos
            // en este sistema, «mamore» si la pidió el funcionario desde su
            // perfil, «sia» si vino de la copia del sistema viejo.
            //
            // Hasta ahora se deducía de `registerUser_id` nulo, que es frágil:
            // esa columna dice quién dio el alta, no por dónde entró, y queda
            // nula por más de un motivo. El default es «propio» porque es lo que
            // corresponde a todo lo que ya está y a lo que carga la pantalla.
            $table->string('origen', 10)->default('propio')->after('solicitud');
        });

        $this->rellenarHistorico();

        Schema::table('licencias', function (Blueprint $table): void {
            // La clave natural pasa a incluir el pedido.
            //
            // Con `(ci, fecha, turno_id)` a secas, un día licenciado quedaba
            // reservado **para siempre**: si el pedido se rechazaba o se daba de
            // baja, esa fila seguía ocupando la clave y no se podía volver a
            // pedir el día sin reescribirla. Y reescribirla borra el historial
            // —qué se pidió, quién lo resolvió y con qué motivo—, que es
            // justamente lo que el funcionario lee en su perfil.
            //
            // Con `solicitud` adentro, dos pedidos distintos del mismo día
            // conviven y cada uno conserva su desenlace. Que no haya **dos
            // licencias vigentes** para el mismo turno lo cuida
            // `RegistroLicencia::anotar()`, que consulta lo ocupado antes de
            // insertar.
            $table->dropUnique(['ci', 'fecha', 'turno_id']);
            $table->unique(['ci', 'fecha', 'turno_id', 'solicitud']);

            // El agrupamiento del listado: `GROUP BY solicitud` con su
            // `MAX(fecha)`, y la búsqueda de la fila que abre cada solicitud.
            $table->index(['solicitud', 'fecha']);
            // El listado filtrado por estado, que es como Recursos Humanos
            // encuentra lo que tiene pendiente.
            $table->index(['estado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::table('licencias', function (Blueprint $table): void {
            $table->dropUnique(['ci', 'fecha', 'turno_id', 'solicitud']);
            $table->dropIndex(['solicitud', 'fecha']);
            $table->dropIndex(['estado', 'fecha']);
            $table->dropColumn(['solicitud', 'origen']);
            $table->unique(['ci', 'fecha', 'turno_id']);
        });
    }

    /**
     * Agrupa lo que ya estaba en la tabla, con la misma clave que la copia del
     * SIA: mismo carnet, mismo momento del pedido y mismo motivo.
     *
     * Por bloques de id y no de una: son más de un millón de filas.
     */
    private function rellenarHistorico(): void
    {
        $maximo = (int) DB::table('licencias')->max('id');

        if ($maximo === 0) {
            return;
        }

        $relleno = DB::raw($this->expresionDeRelleno());

        for ($desde = 0; $desde <= $maximo; $desde += self::LOTE) {
            DB::table('licencias')
                ->whereNull('solicitud')
                ->where('id', '>', $desde)
                ->where('id', '<=', $desde + self::LOTE)
                ->update(['solicitud' => $relleno]);
        }

        // Lo que ya estaba cuando se aplica esta migración vino de la copia del
        // sistema viejo: el circuito de solicitudes desde Mamoré es posterior.
        DB::table('licencias')->update(['origen' => 'sia']);
    }

    /**
     * Los 26 primeros caracteres del `sha2` de `ci|fechaPedido|motivo`, que es lo
     * mismo que calcula en PHP `MigrarLicenciasSia::solicitudDelSia()`.
     *
     * SQLite —la base de las pruebas— no trae funciones de hash, y ahí la tabla
     * está vacía cuando corre la migración: se cae al id con ceros a la izquierda,
     * que deja cada fila en su propia solicitud. Si alguna vez hubiera datos en
     * SQLite, se agruparían con `sia:migrar-licencias`, no acá.
     */
    private function expresionDeRelleno(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "substr('00000000000000000000000000' || id, -26)"
            : "SUBSTR(SHA2(CONCAT_WS('|', TRIM(ci), fechaPedido, TRIM(COALESCE(motivo, ''))), 256), 1, 26)";
    }
};
