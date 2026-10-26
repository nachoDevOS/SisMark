{{--
    Botón «Ayuda» de la barra superior y su ventana: videos que muestran, paso
    a paso, cómo hacer un trámite sin salir de la pantalla en la que se está
    trabajando. Solo aparece si el usuario tiene algún tutorial a mano (ver
    App\Services\ManualUsuario).

    Dos vistas: la biblioteca (una tarjeta por video, con su vista previa en
    bucle) y el reproductor (barra de pasos, velocidad y lista de pasos). Lo
    visto se recuerda en el navegador para ofrecer «Continuar».

    La ventana se lleva a <body> con x-teleport: la topbar es `sticky` con
    z-index 20, y un `fixed` adentro queda atrapado en ese contexto, debajo
    del sidebar.
--}}
@php
    $tutoriales = collect(\App\Services\ManualUsuario::paraUsuario(auth()->user()))
        ->map(function (array $tutorial, string $clave): array {
            $archivo = fn (string $extension): ?string => is_file($ruta = \App\Services\ManualUsuario::archivo($clave, $extension))
                // `v` cambia al regrabar: el navegador guarda el video un día y mostraría el viejo.
                ? route('manual.archivo', ['tutorial' => $clave, 'extension' => $extension, 'v' => filemtime($ruta)])
                : null;

            return [
                'clave' => $clave,
                'titulo' => $tutorial['titulo'],
                'descripcion' => $tutorial['descripcion'],
                'modulo' => $tutorial['modulo'],
                'capitulos' => $tutorial['capitulos'],
                'video' => $archivo('mp4'),
                'portada' => $archivo('jpg'),
                'previa' => $archivo('previa.mp4'),
            ];
        })
        ->values();
@endphp

@if ($tutoriales->isNotEmpty())
    @once
        <script>
            document.addEventListener('alpine:init', () => {
                /** Hasta dónde vio cada tutorial este navegador: para «Continuar» y «Visto». */
                const progreso = {
                    leer(clave) {
                        try {
                            const crudo = localStorage.getItem(`sismark.ayuda.${clave}`);
                            return crudo ? JSON.parse(crudo) : null;
                        } catch (e) {
                            // Navegación privada o almacenamiento lleno: sin progreso, la ayuda anda igual.
                            return null;
                        }
                    },
                    guardar(clave, datos) {
                        try { localStorage.setItem(`sismark.ayuda.${clave}`, JSON.stringify(datos)); } catch (e) { /* ver leer() */ }
                    },
                    // Se ofrece continuar solo si quedó a la mitad: ni recién empezado ni casi terminado.
                    continuarDesde(datos) {
                        return datos && datos.segundo > 5 && datos.duracion - datos.segundo > 8 ? datos.segundo : null;
                    },
                };

                Alpine.data('ayuda', (tutoriales) => ({
                    tutoriales,
                    abierto: false,
                    elegido: null,
                    velocidades: [0.75, 1, 1.25, 1.5],
                    velocidad: 1,
                    segundo: 0,
                    duracion: 0,
                    terminado: false,
                    retomado: null,
                    ultimoGuardado: 0,
                    sinMovimiento: window.matchMedia('(prefers-reduced-motion: reduce)').matches,

                    abrir() {
                        this.reiniciar();
                        // Con un solo video la biblioteca sobra: se abre directo.
                        this.elegido = this.tutoriales.length === 1 ? this.tutoriales[0].clave : null;
                        this.abierto = true;
                    },
                    get tutorial() { return this.tutoriales.find((t) => t.clave === this.elegido) ?? null; },
                    get puedeVolver() { return this.tutoriales.length > 1; },
                    elegir(clave) { this.reiniciar(); this.elegido = clave; },
                    volver() { this.elegido = null; },
                    reiniciar() {
                        this.segundo = 0;
                        this.duracion = 0;
                        this.terminado = false;
                        this.retomado = null;
                        this.ultimoGuardado = 0;
                    },

                    // Biblioteca
                    leer(clave) { return progreso.leer(clave); },
                    avance(clave) {
                        const datos = progreso.leer(clave);
                        return datos && datos.duracion > 0 && ! datos.visto ? datos.segundo / datos.duracion : 0;
                    },
                    accion(clave) {
                        const datos = progreso.leer(clave);
                        const desde = progreso.continuarDesde(datos);
                        if (desde !== null) { return `Continuar desde ${this.minutos(desde)}`; }

                        return datos?.visto ? 'Ver de nuevo' : 'Ver el video';
                    },

                    // Reproductor
                    get capitulos() { return this.tutorial?.capitulos ?? []; },
                    get total() { return this.duracion || ((this.capitulos.at(-1)?.segundo ?? 0) + 10); },
                    // El paso en curso es el último que ya empezó.
                    get pasoActual() {
                        return this.capitulos.reduce((ultimo, c, i) => (c.segundo <= this.segundo + 0.25 ? i : ultimo), 0);
                    },
                    fin(i) { return this.capitulos[i + 1]?.segundo ?? this.total; },
                    lleno(i) {
                        const c = this.capitulos[i];
                        return Math.min(1, Math.max(0, (this.segundo - c.segundo) / (this.fin(i) - c.segundo)));
                    },
                    alCargar(video) {
                        this.duracion = video.duration;
                        video.playbackRate = this.velocidad;
                        const desde = progreso.continuarDesde(progreso.leer(this.elegido));
                        if (desde !== null) {
                            video.currentTime = desde;
                            this.retomado = desde;
                            setTimeout(() => { this.retomado = null; }, 6000);
                        }
                    },
                    alAvanzar(video) {
                        this.segundo = video.currentTime;
                        // Cada 2 s alcanza: guardar en cada cuadro es escribir 4 veces por segundo.
                        if (Math.abs(video.currentTime - this.ultimoGuardado) > 2) {
                            this.ultimoGuardado = video.currentTime;
                            progreso.guardar(this.elegido, {
                                segundo: video.currentTime,
                                duracion: video.duration,
                                visto: progreso.leer(this.elegido)?.visto ?? false,
                            });
                        }
                    },
                    alTerminar() {
                        this.terminado = true;
                        progreso.guardar(this.elegido, { segundo: 0, duracion: this.total, visto: true });
                    },
                    irA(segundo) {
                        const video = this.$refs.video;
                        if (! video) { return; }
                        this.terminado = false;
                        this.retomado = null;
                        video.currentTime = segundo;
                        video.play();
                    },
                    cambiarVelocidad(valor) {
                        this.velocidad = valor;
                        if (this.$refs.video) { this.$refs.video.playbackRate = valor; }
                    },
                    minutos(s) { return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`; },
                }));
            });
        </script>
    @endonce

    <div class="ayuda" x-data="ayuda(@js($tutoriales))"
         x-effect="document.body.style.overflow = abierto ? 'hidden' : ''"
         x-on:keydown.escape.window="abierto = false">
        <button type="button" class="ayuda__boton" x-on:click="abrir()" title="Ayuda: tutoriales en video">
            <x-heroicon-o-question-mark-circle />
            <span class="ayuda__boton-texto">Ayuda</span>
        </button>

        <template x-teleport="body">
            {{-- x-if y no x-show: los videos recién se piden al abrir y se cortan al cerrar. --}}
            <template x-if="abierto">
                {{-- Tocar afuera no la cierra: un clic de más cortaba el video a la mitad. --}}
                <div class="modal-fondo ayuda__fondo"
                     role="dialog" aria-modal="true" aria-labelledby="titulo-ayuda">
                    <div class="ayuda__caja">
                        <header class="ayuda__cabeza">
                            <template x-if="tutorial && puedeVolver">
                                <button type="button" class="ayuda__icono ayuda__icono--boton" x-on:click="volver()"
                                        aria-label="Volver a todos los videos" title="Todos los videos">
                                    <x-heroicon-o-arrow-left />
                                </button>
                            </template>
                            <template x-if="! (tutorial && puedeVolver)">
                                <span class="ayuda__icono"><x-heroicon-o-question-mark-circle /></span>
                            </template>
                            <div class="ayuda__cabeza-texto">
                                <h2 id="titulo-ayuda">Tutoriales en video</h2>
                                <p x-text="tutorial ? tutorial.titulo : 'Videos cortos que muestran, paso a paso, cómo hacer cada trámite.'"></p>
                            </div>
                            <span class="ayuda__contador" x-show="! tutorial">
                                <x-heroicon-o-film />
                                <span x-text="`${tutoriales.length} ${tutoriales.length === 1 ? 'video' : 'videos'}`"></span>
                            </span>
                            <button type="button" class="ayuda__cerrar" x-on:click="abierto = false" aria-label="Cerrar">
                                <x-heroicon-o-x-mark />
                            </button>
                        </header>

                        <div class="ayuda__cuerpo">
                            {{-- Biblioteca --}}
                            <template x-if="! tutorial">
                                <div>
                                    <p class="ayuda__consejo">
                                        <x-heroicon-o-light-bulb />
                                        <span>Elija un video. Mientras mira, toque cualquier paso de la lista para ir directo a esa
                                            parte; si lo deja a la mitad, la próxima vez sigue desde ahí.</span>
                                    </p>

                                    <div class="ayuda__biblioteca">
                                        <template x-for="(t, i) in tutoriales" :key="t.clave">
                                            <button type="button" class="ayuda-tarjeta" x-on:click="elegir(t.clave)"
                                                    :style="`animation-delay: ${i * 80}ms`"
                                                    x-data="{ andando: false, vuelta: 0, conPrevia: t.previa && ! sinMovimiento }">
                                                {{-- Al pasar el mouse la vista previa se apura: invita a entrar. --}}
                                                <span class="ayuda-tarjeta__imagen"
                                                      x-on:mouseenter="$refs.previa && ($refs.previa.playbackRate = 1.6)"
                                                      x-on:mouseleave="$refs.previa && ($refs.previa.playbackRate = 1)">
                                                    <img :src="t.portada" alt="" loading="lazy" :class="! conPrevia && 'ayuda-paneo'">
                                                    <template x-if="conPrevia">
                                                        <video x-ref="previa" :src="t.previa" muted loop autoplay playsinline preload="auto" aria-hidden="true"
                                                               :class="andando && 'ayuda-tarjeta__previa--andando'" class="ayuda-tarjeta__previa"
                                                               x-on:playing="andando = true"
                                                               x-on:timeupdate="vuelta = $event.target.currentTime / ($event.target.duration || 1)"></video>
                                                    </template>
                                                    <span class="ayuda-tarjeta__sombra"></span>
                                                    <span class="ayuda-tarjeta__vivo" x-show="andando" x-cloak>
                                                        <span class="ayuda-ecualizador" aria-hidden="true"><i></i><i></i><i></i></span>
                                                        Vista previa
                                                    </span>
                                                    <span class="ayuda-tarjeta__vuelta" x-show="andando && avance(t.clave) === 0 && ! leer(t.clave)?.visto" x-cloak>
                                                        <span :style="`width: ${vuelta * 100}%`"></span>
                                                    </span>
                                                    <span class="ayuda-tarjeta__modulo" x-text="t.modulo"></span>
                                                    <span class="ayuda-tarjeta__pasos">
                                                        <x-heroicon-o-list-bullet /><span x-text="`${t.capitulos.length} pasos`"></span>
                                                    </span>
                                                    <span class="ayuda-tarjeta__play"><x-heroicon-s-play /></span>
                                                    <span class="ayuda-tarjeta__visto" x-show="leer(t.clave)?.visto" x-cloak>
                                                        <x-heroicon-s-check-circle />Visto
                                                    </span>
                                                    <span class="ayuda-tarjeta__avance" x-show="avance(t.clave) > 0" x-cloak>
                                                        <span :style="`width: ${avance(t.clave) * 100}%`"></span>
                                                    </span>
                                                </span>
                                                <span class="ayuda-tarjeta__texto">
                                                    <strong x-text="t.titulo"></strong>
                                                    <span class="ayuda-tarjeta__descripcion" x-text="t.descripcion"></span>
                                                    <span class="ayuda-tarjeta__accion">
                                                        <x-heroicon-s-play /><span x-text="accion(t.clave)"></span>
                                                    </span>
                                                </span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Reproductor --}}
                            <template x-if="tutorial">
                                <div class="ayuda-reproductor">
                                    <div class="ayuda-reproductor__principal">
                                        <div class="ayuda-reproductor__pantalla">
                                            <video x-ref="video" :src="tutorial.video" :poster="tutorial.portada" controls autoplay
                                                   preload="metadata" playsinline
                                                   x-on:loadedmetadata="alCargar($event.target)"
                                                   x-on:timeupdate="alAvanzar($event.target)"
                                                   x-on:ended="alTerminar()"
                                                   x-on:play="terminado = false"></video>

                                            <div class="ayuda-retomado" x-show="retomado !== null" x-cloak>
                                                <span x-text="`Seguimos donde lo dejó (${minutos(retomado ?? 0)})`"></span>
                                                <button type="button" x-on:click="irA(0)">
                                                    <x-heroicon-o-arrow-path />Desde el inicio
                                                </button>
                                            </div>

                                            <div class="ayuda-final" x-show="terminado" x-cloak>
                                                <div class="ayuda-final__caja">
                                                    <span class="ayuda-final__icono"><x-heroicon-o-sparkles /></span>
                                                    <p class="ayuda-final__titulo">¡Listo, ya lo vio completo!</p>
                                                    <p class="ayuda-final__texto">
                                                        Ahora puede hacer el trámite usted mismo. Si se olvida de un paso, vuelva y
                                                        tóquelo en la lista.
                                                    </p>
                                                    <div class="ayuda-final__botones">
                                                        <button type="button" class="btn btn--gris" x-on:click="irA(0)">
                                                            <x-heroicon-o-arrow-path />Ver de nuevo
                                                        </button>
                                                        <button type="button" class="btn" x-show="puedeVolver" x-on:click="volver()">
                                                            <x-heroicon-o-squares-2x2 />Otros videos
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Un tramo por paso: se llena con el avance y un clic salta a su comienzo. --}}
                                        <div class="ayuda-tramos" role="presentation">
                                            <template x-for="(c, i) in capitulos" :key="c.segundo">
                                                <button type="button" class="ayuda-tramos__tramo" :class="i === pasoActual && 'activo'"
                                                        :style="`flex-grow: ${fin(i) - c.segundo}`"
                                                        :title="`${i + 1}. ${c.titulo}`" :aria-label="`Ir al paso ${i + 1}: ${c.titulo}`"
                                                        x-on:click="irA(c.segundo)">
                                                    <span :style="`width: ${lleno(i) * 100}%`"></span>
                                                </button>
                                            </template>
                                        </div>

                                        <div class="ayuda-controles">
                                            <div class="ayuda-controles__pasos">
                                                <button type="button" class="btn btn--gris" :disabled="pasoActual === 0"
                                                        x-on:click="irA(capitulos[pasoActual - 1].segundo)">
                                                    <x-heroicon-o-chevron-left />Paso anterior
                                                </button>
                                                <button type="button" class="btn" :disabled="pasoActual === capitulos.length - 1"
                                                        x-on:click="irA(capitulos[pasoActual + 1].segundo)">
                                                    Siguiente paso<x-heroicon-o-chevron-right />
                                                </button>
                                            </div>
                                            <div class="ayuda-velocidad" role="group" aria-label="Velocidad">
                                                <x-heroicon-o-forward />
                                                <template x-for="v in velocidades" :key="v">
                                                    <button type="button" :class="velocidad === v && 'activo'" :aria-pressed="velocidad === v"
                                                            x-on:click="cambiarVelocidad(v)" x-text="`${v}×`"></button>
                                                </template>
                                            </div>
                                        </div>

                                        <div class="ayuda-reproductor__titulo">
                                            <h3 x-text="tutorial.titulo"></h3>
                                            <span class="pill pill--neutro" x-text="tutorial.modulo"></span>
                                        </div>
                                        <p class="persona-meta" x-text="tutorial.descripcion"></p>
                                    </div>

                                    <div>
                                        <div class="ayuda-lista__cabeza">
                                            <span x-text="`Paso ${pasoActual + 1} de ${capitulos.length}`"></span>
                                            <span x-text="`${minutos(segundo)} / ${minutos(total)}`"></span>
                                        </div>
                                        {{-- El paso en curso, siempre a la vista en la lista. --}}
                                        <ol class="ayuda-lista"
                                            x-effect="$el.querySelector(`[data-paso='${pasoActual}']`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })">
                                            <template x-for="(c, i) in capitulos" :key="c.segundo">
                                                <li :data-paso="i">
                                                    <button type="button" class="ayuda-paso" x-on:click="irA(c.segundo)"
                                                            :class="{
                                                                'ayuda-paso--hecho': i < pasoActual || (terminado && i === pasoActual),
                                                                'ayuda-paso--actual': i === pasoActual && ! terminado,
                                                            }">
                                                        <span class="ayuda-paso__numero">
                                                            <span class="ayuda-paso__latido" x-show="i === pasoActual && ! terminado"></span>
                                                            <span x-show="! (i < pasoActual || (terminado && i === pasoActual))" x-text="i + 1"></span>
                                                            <span x-show="i < pasoActual || (terminado && i === pasoActual)"><x-heroicon-s-check /></span>
                                                        </span>
                                                        <span class="ayuda-paso__titulo" x-text="c.titulo"></span>
                                                        <span class="ayuda-paso__tiempo" x-text="minutos(c.segundo)"></span>
                                                    </button>
                                                </li>
                                            </template>
                                        </ol>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </div>
@endif
