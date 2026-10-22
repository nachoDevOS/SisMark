{{-- Estado y lógica de asignar un turno (`fichaAsignacion()`): la ficha del
     funcionario, el contrato elegido y las fechas. Lo comparten el modal de la
     ficha y la pantalla «Turnos asignados → Asignar». --}}
@once
    <script>
        window.fichaAsignacion = (inicial) => ({
            ficha: null,
            cargandoFicha: false,
            errorFicha: '',
            contratoId: inicial.contratoId || null,
            turnoNombre: inicial.turnoNombre || '',
            desde: inicial.desde,
            hasta: inicial.hasta,
            minDesde: null,
            maxHasta: null,
            async cargarFicha(ci) {
                this.ficha = null;
                this.errorFicha = '';
                if (! ci) { return; }
                this.cargandoFicha = true;
                try {
                    const resp = await fetch(`{{ route('turnos-asignados.ficha') }}?ci=${encodeURIComponent(ci)}`, { headers: { 'Accept': 'application/json' } });
                    if (! resp.ok) { throw new Error(); }
                    this.ficha = await resp.json();
                    // Si volvió de un error de validación se respeta lo elegido y
                    // sus fechas. Si no, se elige solo el único contrato vigente
                    // libre; sin vigentes, el único anterior que queda por
                    // regularizar.
                    const libres = this.contratosLibres();
                    const elegido = libres.find(c => String(c.id) === String(this.contratoId));
                    const vigentes = libres.filter(c => ! c.regularizar);
                    if (elegido) {
                        this.marcarContrato(elegido, false);
                    } else {
                        this.contratoId = null;
                        if (vigentes.length === 1) {
                            this.marcarContrato(vigentes[0], true);
                        } else if (vigentes.length === 0 && libres.length === 1) {
                            this.marcarContrato(libres[0], true);
                        }
                    }
                } catch (e) {
                    this.errorFicha = 'No se pudo cargar la información del funcionario. Reintentá.';
                } finally {
                    this.cargandoFicha = false;
                }
            },
            // Se le puede asignar turno si le queda algo sin control. Con
            // `cobertura` (el que ya terminó, o el vigente que ya tiene turno)
            // lo dice la cuenta de tramos; sin ella (vigente sin turno), sí.
            elegible(contrato) {
                return contrato.cobertura ? contrato.cobertura.estado !== 'completa' : ! contrato.turno;
            },
            contratosLibres() {
                return this.ficha ? this.ficha.contratos.filter(c => this.elegible(c)) : [];
            },
            // Un contrato que ya terminó y quedó cubierto —con su turno o
            // entero con horarios del sistema anterior— no tiene nada que
            // hacer acá: no se muestra. Los vigentes se ven siempre.
            cubierto(contrato) {
                return contrato.regularizar && contrato.cobertura?.estado === 'completa';
            },
            contratosVisibles() {
                return this.ficha ? this.ficha.contratos.filter(c => ! this.cubierto(c)) : [];
            },
            // Con Mamoré y contratos cargados, ninguno admite turno: no hay
            // nada que asignar ni que regularizar.
            nadaQueAsignar() {
                return Boolean(this.ficha?.mamore && ! this.ficha.errorContratos
                    && this.ficha.contratos.length > 0 && this.contratosLibres().length === 0);
            },
            // El tramo que cubre el turno: al regularizar, el primero que quedó
            // sin control; si no, el contrato.
            inicioLibre(contrato) {
                return contrato.cobertura?.libreDesde || contrato.desde;
            },
            finLibre(contrato) {
                return contrato.cobertura ? contrato.cobertura.libreHasta : contrato.hasta;
            },
            // Las fechas salen del contrato. El fin es el del contrato y no se
            // toca (solo un contrato abierto deja elegirlo); el inicio se puede
            // atrasar, no adelantar. El inicio propuesto es hoy si el contrato
            // ya empezó: arrancar en su inicio controlaría hacia atrás meses
            // que nadie marcó con este turno, y los reportes se llenarían de
            // faltas. Al regularizar es al revés: se propone todo lo que quedó
            // sin control, porque justamente se quiere controlar ese período.
            marcarContrato(contrato, conFechas) {
                this.contratoId = contrato.id;
                this.minDesde = this.inicioLibre(contrato);
                this.maxHasta = this.finLibre(contrato);
                if (this.finLibre(contrato)) {
                    this.hasta = this.finLibre(contrato);
                }
                if (conFechas) {
                    this.desde = contrato.cobertura || contrato.desde > this.hoy() ? this.inicioLibre(contrato) : this.hoy();
                    this.hasta = this.finLibre(contrato) || '';
                }
            },
            // Con contrato de fecha de fin, el fin del turno es ese y no se edita.
            finFijo() {
                const contrato = this.contratoElegido();
                return Boolean(contrato && this.finLibre(contrato));
            },
            regularizando() {
                return this.contratoElegido()?.regularizar === true;
            },
            contratoElegido() {
                return this.ficha?.contratos.find(c => String(c.id) === String(this.contratoId)) || null;
            },
            // El selector de turno vive en su propio componente: el nombre del
            // elegido se toma del `change` que sube hasta el formulario.
            notarTurno(evento) {
                if (evento.target.name === 'turno_id') { this.turnoNombre = evento.target.dataset.nombre || ''; }
            },
            fechaTexto(iso) {
                if (! iso) { return ''; }
                const [anio, mes, dia] = iso.split('-');
                return `${dia}/${mes}/${anio}`;
            },
            hoy() {
                const fecha = new Date();
                return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
            },
            puedeAsignar() {
                return this.ficha !== null && (! this.ficha.mamore || this.contratoId !== null);
            },
        });
    </script>
@endonce
