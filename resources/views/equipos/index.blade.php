@extends('layouts.app')

@section('titulo', 'Biométricos')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-computer-desktop /></span>
            <h1>Biométricos</h1>
        </div>
        <div style="display: flex; gap: .5rem; flex-wrap: wrap;">
            <a href="{{ route('equipos.auditoria') }}" class="btn btn--gris"><x-heroicon-o-clipboard-document-list />Bitácora</a>
            <a href="{{ route('equipos.create') }}" class="btn"><x-heroicon-o-plus />Nuevo equipo</a>
        </div>
    </div>

    <x-tabla-filtros :action="route('equipos.index')" :busqueda="$busqueda"
                     :por-pagina="$porPagina" placeholder="Buscar por nombre, IP o ubicación…" />

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>IP</th>
                    <th>Puerto</th>
                    <th>Ubicación</th>
                    <th>Algoritmo</th>
                    <th>En línea</th>
                    <th>Activo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($equipos as $equipo)
                    <tr>
                        <td><a href="{{ route('equipos.show', $equipo) }}"><strong>{{ $equipo->nombre }}</strong></a></td>
                        <td>{{ $equipo->ip }}</td>
                        <td>{{ $equipo->puerto }}</td>
                        <td>{{ $equipo->ubicacion ?? '—' }}</td>
                        <td>{{ $equipo->algoritmo ?? 'Sin detectar' }}</td>
                        <td>
                            <span class="pill {{ $equipo->en_linea ? 'pill--ok' : 'pill--no' }}">
                                {{ $equipo->en_linea ? 'Sí' : 'No' }}
                            </span>
                        </td>
                        <td>
                            <span class="pill {{ $equipo->activo ? 'pill--ok' : 'pill--no' }}">
                                {{ $equipo->activo ? 'Sí' : 'No' }}
                            </span>
                        </td>
                        <td>
                            <div class="acciones">
                                <form action="{{ route('equipos.probar-conexion', $equipo) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn-icon" title="Probar conexión" aria-label="Probar conexión"><x-heroicon-o-signal /></button>
                                </form>
                                <a href="{{ route('equipos.edit', $equipo) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                                <div class="dropdown"
                                     x-data="{
                                         open: false,
                                         modal: false,
                                         modalLimpiar: false,
                                         enviando: false,
                                         exportando: false,
                                         limpiando: false,
                                         respaldando: false,
                                         confirmacion: '',
                                         motivo: '',
                                         errorCsv: '',
                                         errorLimpiar: '',
                                         desde: '{{ now()->startOfMonth()->toDateString() }}',
                                         hasta: '{{ now()->toDateString() }}',
                                         async descargarCsv() {
                                             if (this.exportando) { return; }
                                             this.exportando = true;
                                             this.errorCsv = '';
                                             try {
                                                 const url = `{{ route('equipos.marcaciones.exportar', $equipo) }}?desde=${this.desde}&hasta=${this.hasta}`;
                                                 const resp = await fetch(url, { headers: { 'Accept': 'text/csv' } });
                                                 const tipo = resp.headers.get('Content-Type') || '';
                                                 if (! resp.ok || ! tipo.includes('csv')) {
                                                     this.errorCsv = 'No se pudo leer el equipo. Revisá que esté en línea e intentá de nuevo.';
                                                     return;
                                                 }
                                                 const blob = await resp.blob();
                                                 const enlace = document.createElement('a');
                                                 enlace.href = URL.createObjectURL(blob);
                                                 enlace.download = `marcaciones-{{ str($equipo->nombre)->slug() }}-${this.hasta || new Date().toISOString().slice(0, 10)}.csv`;
                                                 document.body.appendChild(enlace);
                                                 enlace.click();
                                                 enlace.remove();
                                                 URL.revokeObjectURL(enlace.href);
                                                 this.modal = false;
                                             } catch (e) {
                                                 this.errorCsv = 'No se pudo descargar. Revisá la conexión y probá otra vez.';
                                             } finally {
                                                 this.exportando = false;
                                             }
                                         },
                                         {{-- Se arma la fecha a mano y no con toISOString(), que devuelve UTC:
                                              en Bolivia (UTC-4) eso adelanta un día a partir de las 20:00. --}}
                                         aIso(fecha) {
                                             const dosDigitos = (n) => String(n).padStart(2, '0');

                                             return `${fecha.getFullYear()}-${dosDigitos(fecha.getMonth() + 1)}-${dosDigitos(fecha.getDate())}`;
                                         },
                                         fechaHoy() {
                                             return this.aIso(new Date());
                                         },
                                         rangoUltimos(dias) {
                                             const desde = new Date();
                                             desde.setDate(desde.getDate() - (dias - 1));
                                             this.desde = this.aIso(desde);
                                             this.hasta = this.fechaHoy();
                                         },
                                         rangoEsteMes() {
                                             const hoy = new Date();
                                             this.desde = this.aIso(new Date(hoy.getFullYear(), hoy.getMonth(), 1));
                                             this.hasta = this.aIso(hoy);
                                         },
                                         rangoMesPasado() {
                                             const hoy = new Date();
                                             this.desde = this.aIso(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1));
                                             // Día 0 del mes actual = último día del mes anterior.
                                             this.hasta = this.aIso(new Date(hoy.getFullYear(), hoy.getMonth(), 0));
                                         },
                                         async respaldarYLimpiar() {
                                             if (this.respaldando || this.limpiando) { return; }
                                             this.respaldando = true;
                                             this.errorLimpiar = '';
                                             try {
                                                 // Sin desde/hasta el export trae TODO el historial del equipo:
                                                 // es lo mismo que se está por borrar, así que el respaldo queda completo.
                                                 const resp = await fetch('{{ route('equipos.marcaciones.exportar', $equipo) }}', { headers: { 'Accept': 'text/csv' } });
                                                 const tipo = resp.headers.get('Content-Type') || '';
                                                 if (! resp.ok || ! tipo.includes('csv')) {
                                                     this.errorLimpiar = 'No se pudo descargar el respaldo, así que no se borró nada. Revisá que el equipo esté en línea e intentá de nuevo.';
                                                     return;
                                                 }
                                                 const blob = await resp.blob();
                                                 const enlace = document.createElement('a');
                                                 enlace.href = URL.createObjectURL(blob);
                                                 {{-- OJO: nada de comillas dobles acá adentro. Todo este bloque
                                                      vive dentro del atributo x-data="...", así que una comilla
                                                      doble lo corta y el resto del JS se ve como texto en la tabla. --}}
                                                 // Nombre distinto al del export normal, con respaldo adelante:
                                                 // se ve de una que es el respaldo previo a un borrado, de qué
                                                 // equipo salió, y todos quedan juntos al ordenar la carpeta.
                                                 enlace.download = `respaldo-{{ str($equipo->nombre)->slug() }}-${this.fechaHoy()}.csv`;
                                                 document.body.appendChild(enlace);
                                                 enlace.click();
                                                 enlace.remove();

                                                 // Enviar el form recarga la página. Se le da un respiro al
                                                 // navegador para que registre la descarga antes de navegar:
                                                 // si no, la descarga recién disparada puede quedar cancelada
                                                 // y se borraría el equipo sin respaldo en disco.
                                                 this.limpiando = true;
                                                 setTimeout(() => {
                                                     URL.revokeObjectURL(enlace.href);
                                                     this.$refs.formLimpiar.submit();
                                                 }, 1500);
                                             } catch (e) {
                                                 this.errorLimpiar = 'No se pudo descargar el respaldo, así que no se borró nada. Revisá la conexión y probá otra vez.';
                                             } finally {
                                                 this.respaldando = false;
                                             }
                                         },
                                     }"
                                     x-on:click.outside="open = false">
                                    <button type="button" class="dropdown-toggle" x-on:click="open = !open" aria-haspopup="true" :aria-expanded="open">
                                        Mas <x-heroicon-o-chevron-down />
                                    </button>
                                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                                        <button type="button" x-on:click="modal = true; open = false"><x-heroicon-o-arrow-down-tray />Exportar marcaciones</button>

                                        <div class="dropdown-menu__peligro">
                                            <button type="button" x-on:click="modalLimpiar = true; open = false; confirmacion = ''; motivo = ''"><x-heroicon-o-archive-box-x-mark />Borrar marcaciones</button>
                                            <x-boton-eliminar variante="menu" etiqueta="Eliminar equipo"
                                                              al-hacer-clic="open = false"
                                                              :accion="route('equipos.destroy', $equipo)"
                                                              :mensaje="'Se elimina el equipo «'.$equipo->nombre.'»: deja de aparecer en el listado y de participar en las sincronizaciones. Las marcaciones que ya estén en la base del SIA no se tocan, y el reloj tampoco se borra.'" />
                                        </div>
                                    </div>

                                    {{-- Modal de rango: se elige una vez y sirve para las dos acciones
                                         (descargar CSV o enviar directo a la BD del SIA), sin pasar por
                                         la vista en vivo que renderiza la tabla y es más lenta. --}}
                                    <div class="modal-fondo" x-show="modal" x-cloak
                                         x-on:click.self="modal = false" x-on:keydown.escape.window="modal = false">
                                        <div class="modal-caja modal-caja--ancha">
                                            <h2>Marcaciones de «{{ $equipo->nombre }}»</h2>
                                            <p class="modal-bajada">Elegí el rango y después qué hacer con esas marcaciones.</p>

                                            <div class="grid-2">
                                                <div class="campo">
                                                    <label for="desde-{{ $equipo->id }}">Desde</label>
                                                    <input type="date" id="desde-{{ $equipo->id }}" x-model="desde">
                                                </div>
                                                <div class="campo">
                                                    <label for="hasta-{{ $equipo->id }}">Hasta</label>
                                                    <input type="date" id="hasta-{{ $equipo->id }}" x-model="hasta">
                                                </div>
                                            </div>

                                            {{-- Atajos: evitan tipear las dos fechas en los casos de siempre. --}}
                                            <div class="rangos-rapidos">
                                                <button type="button" class="rango-chip" x-on:click="rangoUltimos(1)">Hoy</button>
                                                <button type="button" class="rango-chip" x-on:click="rangoUltimos(7)">Últimos 7 días</button>
                                                <button type="button" class="rango-chip" x-on:click="rangoEsteMes()">Este mes</button>
                                                <button type="button" class="rango-chip" x-on:click="rangoMesPasado()">Mes pasado</button>
                                            </div>

                                            <div class="modal-opciones">
                                                <button type="button" class="modal-opcion" x-on:click="descargarCsv()"
                                                        :disabled="exportando || enviando">
                                                    <span class="modal-opcion__icono" x-show="! exportando"><x-heroicon-o-arrow-down-tray /></span>
                                                    <span class="modal-opcion__icono" x-show="exportando" x-cloak><span class="spinner-anillo spinner-anillo--verde"></span></span>
                                                    <span>
                                                        <span class="modal-opcion__titulo" x-text="exportando ? 'Descargando…' : 'Descargar CSV'"></span>
                                                        <span class="modal-opcion__ayuda">Baja un archivo a tu computadora. No modifica nada.</span>
                                                    </span>
                                                </button>

                                                {{-- Sin rango a propósito: el reloj entrega su historial completo
                                                     igual, así que se registra todo lo que falte. El rango de arriba
                                                     sigue valiendo para el CSV, que es lo que el usuario mira. --}}
                                                <form method="POST" action="{{ route('equipos.marcaciones.sincronizar', $equipo) }}"
                                                      x-on:submit="if (! confirm('¿Registrar en el sistema todas las marcaciones del reloj que falten?')) { $event.preventDefault(); return; } enviando = true">
                                                    @csrf
                                                    <button type="submit" class="modal-opcion modal-opcion--principal"
                                                            :disabled="exportando || enviando">
                                                        <span class="modal-opcion__icono" x-show="! enviando"><x-heroicon-o-arrow-up-tray /></span>
                                                        <span class="modal-opcion__icono" x-show="enviando" x-cloak><span class="spinner-anillo"></span></span>
                                                        <span>
                                                            <span class="modal-opcion__titulo" x-text="enviando ? 'Registrando…' : 'Registrar en el sistema'"></span>
                                                            <span class="modal-opcion__ayuda">Guarda todas las marcaciones del reloj que falten. Ignora el rango y no baja ningún archivo.</span>
                                                        </span>
                                                    </button>
                                                </form>
                                            </div>

                                            <p x-show="errorCsv" x-cloak x-text="errorCsv"
                                               style="margin: .75rem 0 0; color: #dc2626; font-size: .8125rem;"></p>

                                            <div class="modal-pie">
                                                <button type="button" class="enlace-cancelar" x-on:click="modal = false"
                                                        :disabled="exportando || enviando">Cancelar</button>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Modal de limpieza: borra el buffer de marcaciones DEL EQUIPO.
                                         El protocolo ZK no permite borrar por rango, así que se va todo
                                         el historial del reloj y no hay vuelta atrás. Por eso no lleva
                                         fechas, baja un CSV de respaldo con TODO antes de borrar, y
                                         exige escribir LIMPIAR para habilitar el botón. --}}
                                    <div class="modal-fondo" x-show="modalLimpiar" x-cloak
                                         x-on:click.self="modalLimpiar = false" x-on:keydown.escape.window="modalLimpiar = false">
                                        <div class="modal-caja">
                                            <h2>Borrar las marcaciones de «{{ $equipo->nombre }}»</h2>
                                            <p style="margin: 0 0 .75rem; font-size: .8125rem; line-height: 1.5;">
                                                Primero se descarga un CSV con <strong>todo</strong> el historial del equipo
                                                (<code>respaldo-{{ str($equipo->nombre)->slug() }}-{{ now()->toDateString() }}.csv</code>)
                                                y recién después se borra. Si la descarga falla, no se borra nada.
                                            </p>
                                            <p style="margin: 0 0 .75rem; font-size: .8125rem; line-height: 1.5;">
                                                El reloj no permite borrar solo un rango de fechas: se van
                                                <strong>todas</strong> las marcaciones y la acción
                                                <strong>no se puede deshacer</strong>.
                                            </p>
                                            <p style="margin: 0 0 1rem; font-size: .8125rem; line-height: 1.5; color: #6b7280;">
                                                Los usuarios y sus huellas no se tocan, y lo que ya está en la base del
                                                SIA tampoco.
                                            </p>
                                            <div class="campo">
                                                <label for="motivo-{{ $equipo->id }}">¿Por qué se borran? (queda en la bitácora)</label>
                                                <textarea id="motivo-{{ $equipo->id }}" x-model="motivo" rows="2"
                                                          placeholder="Ej.: memoria del equipo llena, ya sincronizado al SIA"></textarea>
                                            </div>
                                            <div class="campo">
                                                <label for="confirmar-{{ $equipo->id }}">Escribí LIMPIAR para confirmar</label>
                                                <input type="text" id="confirmar-{{ $equipo->id }}" x-model="confirmacion"
                                                       autocomplete="off" placeholder="LIMPIAR">
                                            </div>
                                            <div class="modal-acciones">
                                                <button type="button" class="btn btn--gris" x-on:click="modalLimpiar = false" :disabled="respaldando || limpiando">Cancelar</button>
                                                <form method="POST" action="{{ route('equipos.marcaciones.limpiar', $equipo) }}" x-ref="formLimpiar">
                                                    @csrf
                                                    <input type="hidden" name="motivo" :value="motivo">
                                                    <button type="button" class="btn btn--peligro" x-on:click="respaldarYLimpiar()"
                                                            :disabled="respaldando || limpiando || motivo.trim().length < 5 || confirmacion.trim().toUpperCase() !== 'LIMPIAR'">
                                                        <span class="btn__contenido" x-show="! respaldando && ! limpiando"><x-heroicon-o-backspace />Descargar todo y borrar</span>
                                                        <span class="btn__contenido" x-show="respaldando" x-cloak><span class="spinner-anillo"></span>Descargando respaldo…</span>
                                                        <span class="btn__contenido" x-show="limpiando" x-cloak><span class="spinner-anillo"></span>Borrando…</span>
                                                    </button>
                                                </form>
                                            </div>
                                            <p x-show="errorLimpiar" x-cloak x-text="errorLimpiar"
                                               style="margin: .75rem 0 0; color: #dc2626; font-size: .8125rem;"></p>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="vacio">Aún no hay equipos registrados.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacion">
        {{ $equipos->links() }}
    </div>
@endsection
